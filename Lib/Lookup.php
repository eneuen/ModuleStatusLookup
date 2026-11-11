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
 *
 * Every value set here matches a fixed pattern: the dialplan uses them in
 * Playback and Goto.
 *
 * Plain PHP, no core dependencies, so it is unit-tested without a PBX.
 */
final class Lookup
{
    public const array RESULTS = ['hit', 'invalid', 'not_found', 'expired', 'rate_limited', 'fault'];

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
     */
    public function __construct(
        private readonly PDO $db,
        private readonly array $settings,
        private readonly DateTimeZone $timezone,
        private $promptPath,
    ) {
    }

    /**
     * @param string $input What the caller typed (Read's result).
     * @param string $caller The caller's number, digits only; '' when withheld.
     * @param int $missesThisCall Failed tries earlier in this call, not yet in the log.
     *
     * @return array<string, string> Channel variable => value; every SL_ variable is set.
     */
    public function find(string $input, string $caller, int $missesThisCall, int $now): array
    {
        $vars = ['SL_RESULT' => '', 'SL_ID' => '', 'SL_STATUS' => '', 'SL_PROMPT' => '', 'SL_EXTRA' => 'none',
            'SL_DATE' => '', 'SL_ACTION' => '', 'SL_TRANSFER' => ''];

        if ($this->rateLimited(self::digits($caller), $missesThisCall, $now)) {
            return ['SL_RESULT' => 'rate_limited'] + $vars;
        }
        [$min, $max] = self::idLengths($this->settings);
        if (preg_match('/^[0-9]{' . $min . ',' . $max . '}\z/', $input) !== 1) {
            return ['SL_RESULT' => 'invalid'] + $vars;
        }
        $vars['SL_ID'] = $input;

        $stmt = $this->db->prepare('SELECT r.status_code, r.status_date, r.expires_on, s.code, s.prompt_id, s.spoken_extra,
                s.after_action, s.transfer_number
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
