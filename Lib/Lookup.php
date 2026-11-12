<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * One try at a lookup: what the caller typed, checked and looked up, as the
 * channel variables the dialplan acts on.
 *
 * Results (SL_RESULT):
 * - hit: the record exists and hasn't expired. SL_PROMPT is the status
 *   sentence, SL_EXTRA what follows it, SL_ACTION what happens next.
 * - invalid: not digits within the configured length.
 * - not_found / expired: no such record, or one past its last day. The
 *   caller hears the same "not found" for both.
 * - rate_limited: this caller, or all callers together, missed too many
 *   lookups in the last hour; the call goes straight to a person.
 * - fault: the record's status or its prompt can't be used. The caller is
 *   sent to a person, never left in silence.
 * - need_pin: the status asks for the record's PIN; the lookup script asks
 *   for it and looks up again with it. Nothing about the status is returned
 *   before the PIN matches.
 * - pin_failed / callerid_failed: a wrong PIN, or a caller number that isn't
 *   the record's phone number when the status requires it. The caller hears
 *   "not found", as for an unknown ID, so a guess learns nothing.
 * - no_pin / no_phone: the status asks for a PIN, or is only for the
 *   record's phone, but the record has none; the caller is put through to a
 *   person.
 * - pin_locked: the record's ID had too many wrong PINs in the last day,
 *   from any caller; the caller is put through to a person instead of
 *   being asked again.
 *
 * Every value set here matches a fixed pattern: the dialplan uses them in
 * Playback and Goto.
 *
 * Plain PHP, no core dependencies, so it is unit-tested without a PBX.
 */
final class Lookup
{
    public const array RESULTS = ['hit', 'invalid', 'not_found', 'expired', 'rate_limited', 'fault', 'need_pin', 'no_pin', 'no_phone',
        'pin_failed', 'pin_locked', 'callerid_failed'];

    /** Wrong PINs for one ID, from all callers together, before its PIN is no longer asked for. */
    public const int PIN_FAILS_LIMIT = 10;

    /** The period those wrong PINs are counted over, in seconds. */
    public const int PIN_FAILS_WINDOW = 86400;

    /** Caller numbers match a record's phone on their last digits, at least this many (country prefixes differ). */
    public const int PHONE_DIGITS = 7;

    /**
     * Compared on at most this many last digits: beyond them, the same number is written with a country code
     * or a national trunk prefix (+49 30 1234567, 030 1234567; +7 916..., 8 916...).
     */
    public const int PHONE_COMPARE = 9;

    public const array EXTRAS = ['none', 'id', 'date'];

    public const array ACTIONS = ['hangup', 'repeat', 'transfer'];

    /** A sound file path Asterisk can play, without extension. */
    public const string PATH_PATTERN = '/^\/[A-Za-z0-9_\/.-]{1,250}\z/';

    /** A number the caller can be sent to. */
    public const string NUMBER_PATTERN = '/^[0-9]{2,8}\z/';

    private const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}\z/';

    private const int HOUR = 3600;

    /**
     * @param array<string, int|string> $settings Settings::load()
     * @param callable(int): ?string $promptPath A PBX sound file ID => path for Playback, or null.
     * @param Pin|null $pin Checks PINs; null when no PIN can be checked (then a status that asks for one puts the caller through).
     */
    public function __construct(
        private readonly PDO $db,
        private readonly array $settings,
        private readonly DateTimeZone $timezone,
        private $promptPath,
        private readonly ?Pin $pin = null,
    ) {
    }

    /**
     * @param string $input What the caller typed (Read's result).
     * @param string $caller The caller's number, digits only; '' when withheld.
     * @param int $missesThisCall Failed tries earlier in this call, not yet in the log.
     * @param string|null $typedPin The PIN the caller typed, on the second lookup of a status that asks for one.
     * @param int $pinFailsThisCall Wrong PINs for this same ID earlier in this call, not yet in the log.
     *
     * @return array<string, string> Channel variable => value; every SL_ variable is set.
     */
    public function find(string $input, string $caller, int $missesThisCall, int $now, ?string $typedPin = null, int $pinFailsThisCall = 0): array
    {
        $vars = ['SL_RESULT' => '', 'SL_ID' => '', 'SL_STATUS' => '', 'SL_PROMPT' => '', 'SL_EXTRA' => 'none',
            'SL_DATE' => '', 'SL_ACTION' => '', 'SL_TRANSFER' => ''];

        // Being asked for the PIN already counted a miss (until the PIN is right); it mustn't refuse the right PIN.
        if ($this->rateLimited(self::digits($caller), $typedPin === null ? $missesThisCall : max(0, $missesThisCall - 1), $now)) {
            return ['SL_RESULT' => 'rate_limited'] + $vars;
        }
        [$min, $max] = self::idLengths($this->settings);
        if (preg_match('/^[0-9]{' . $min . ',' . $max . '}\z/', $input) !== 1) {
            return ['SL_RESULT' => 'invalid'] + $vars;
        }
        $vars['SL_ID'] = $input;

        $stmt = $this->db->prepare('SELECT r.status_code, r.status_date, r.expires_on, r.pin_hash, r.phone, s.code, s.prompt_id, s.spoken_extra,
                s.after_action, s.transfer_number, s.requires_pin, s.requires_callerid
            FROM m_StatusLookupRecords r LEFT JOIN m_StatusLookupStatuses s ON s.code = r.status_code
            WHERE r.lookup_id = ?');
        $stmt->execute([$input]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['SL_RESULT' => 'not_found'] + $vars;
        }
        $today = (new DateTimeImmutable("@$now"))->setTimezone($this->timezone)->format('Y-m-d');
        // A last day that isn't a YYYY-MM-DD date counts as past: a stale status is worse than none.
        $expires = (string)($row['expires_on'] ?? '');
        if ($expires !== '' && (preg_match(self::DATE_PATTERN, $expires) !== 1 || $expires < $today)) {
            return ['SL_RESULT' => 'expired'] + $vars;
        }

        // The status's own checks come before anything about it is returned: first the caller's number, then the PIN.
        if (($row['requires_callerid'] ?? '0') === '1') {
            $phone = (string)($row['phone'] ?? '');
            if (strlen(self::digits($phone)) < self::PHONE_DIGITS) {
                return ['SL_RESULT' => 'no_phone'] + $vars;
            }
            if (!self::samePhone($caller, $phone)) {
                return ['SL_RESULT' => 'callerid_failed'] + $vars;
            }
        }
        if (($row['requires_pin'] ?? '0') === '1') {
            $stored = (string)($row['pin_hash'] ?? '');
            if ($stored === '' || $this->pin === null) {
                return ['SL_RESULT' => 'no_pin'] + $vars;
            }
            if ($this->pinFails($input, $now) + $pinFailsThisCall >= self::PIN_FAILS_LIMIT) {
                return ['SL_RESULT' => 'pin_locked'] + $vars;
            }
            if ($typedPin === null) {
                return ['SL_RESULT' => 'need_pin'] + $vars;
            }
            if (!$this->pin->matches($typedPin, $stored)) {
                return ['SL_RESULT' => 'pin_failed'] + $vars;
            }
        }

        $prompt = $row['code'] === null ? null : ($this->promptPath)((int)($row['prompt_id'] ?? 0));
        $action = (string)($row['after_action'] ?? '');
        $transfer = (string)($row['transfer_number'] ?? '');
        if ($prompt === null || preg_match(self::PATH_PATTERN, $prompt) !== 1 || !in_array($action, self::ACTIONS, true)
            || ($action === 'transfer' && preg_match(self::NUMBER_PATTERN, $transfer) !== 1)) {
            return ['SL_RESULT' => 'fault', 'SL_STATUS' => self::code((string)$row['status_code'])] + $vars;
        }

        $extra = (string)($row['spoken_extra'] ?? 'none');
        $date = $extra === 'date' ? $this->dateEpoch((string)($row['status_date'] ?? '')) : '';
        if (!in_array($extra, self::EXTRAS, true) || ($extra === 'date' && $date === '')) {
            $extra = 'none';
        }

        return [
            'SL_RESULT'   => 'hit',
            'SL_STATUS'   => self::code((string)$row['code']),
            'SL_PROMPT'   => $prompt,
            'SL_EXTRA'    => $extra,
            'SL_DATE'     => $extra === 'date' ? $date : '',
            'SL_ACTION'   => $action,
            'SL_TRANSFER' => $action === 'transfer' ? $transfer : '',
        ] + $vars;
    }

    /**
     * The shortest and longest ID a caller may type: at least 2 digits, so 0
     * alone always means "speak to someone", and at most 20.
     *
     * @param array<string, int|string> $settings
     *
     * @return array{int, int}
     */
    public static function idLengths(array $settings): array
    {
        $min = max(2, min(20, (int)$settings['id_min_length']));

        return [$min, max($min, min(20, (int)$settings['id_max_length']))];
    }

    /**
     * Whether a caller's number is the record's phone number: both digits
     * only, compared on their last digits (the shorter one's length, at
     * most PHONE_COMPARE and at least PHONE_DIGITS), so +49 30 1234567 and
     * 030 1234567 match. A
     * withheld number or a record without a phone never matches.
     */
    public static function samePhone(string $caller, string $phone): bool
    {
        $a = self::digits($caller);
        $b = self::digits($phone);
        $n = min(strlen($a), strlen($b), self::PHONE_COMPARE);
        if ($n < self::PHONE_DIGITS) {
            return false;
        }

        return substr($a, -$n) === substr($b, -$n);
    }

    /** Digits only, at most 32 of them: what the module keeps of a caller's number. */
    public static function digits(string $number): string
    {
        return substr((string)preg_replace('/\D+/', '', $number), 0, 32);
    }

    /**
     * Whether this caller (withheld callers share one bucket), or all callers
     * together, missed at least their limit in the last hour. A limit of 0 is
     * off. Test calls don't count.
     */
    private function rateLimited(string $caller, int $missesThisCall, int $now): bool
    {
        $since = $now - self::HOUR;
        $perCaller = (int)$this->settings['miss_limit_caller'];
        $total = (int)$this->settings['miss_limit_total'];
        if ($perCaller > 0) {
            $stmt = $this->db->prepare("SELECT COALESCE(SUM(misses), 0) FROM m_StatusLookupCallLog
                WHERE caller = ? AND started_at >= ? AND is_test = '0'");
            $stmt->execute([$caller, $since]);
            if ((int)$stmt->fetchColumn() + $missesThisCall >= $perCaller) {
                return true;
            }
        }
        if ($total > 0) {
            $stmt = $this->db->prepare("SELECT COALESCE(SUM(misses), 0) FROM m_StatusLookupCallLog
                WHERE started_at >= ? AND is_test = '0'");
            $stmt->execute([$since]);
            if ((int)$stmt->fetchColumn() + $missesThisCall >= $total) {
                return true;
            }
        }

        return false;
    }

    /** Wrong PINs typed for this ID in the last PIN_FAILS_WINDOW, by any caller (test calls too: they are real guesses). */
    private function pinFails(string $id, int $now): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(fails), 0) FROM m_StatusLookupPinFails WHERE pin_id = ? AND started_at >= ?');
        $stmt->execute([$id, $now - self::PIN_FAILS_WINDOW]);

        return (int)$stmt->fetchColumn();
    }

    /** Noon of a YYYY-MM-DD date in the PBX's time zone, as epoch seconds, or '' when it isn't one. */
    private function dateEpoch(string $date): string
    {
        if (preg_match(self::DATE_PATTERN, $date) !== 1) {
            return '';
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$date 12:00", $this->timezone);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return '';
        }

        return (string)$parsed->getTimestamp();
    }

    private static function code(string $code): string
    {
        return preg_match('/^[A-Z0-9_]{1,32}\z/', $code) === 1 ? $code : '';
    }
}
