<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * The records callers look up: list and search, add, change, bulk status
 * change, delete and CSV export.
 *
 * - A lookup ID is the digits a caller types, kept as text (leading zeros
 *   count), within the call flow's ID lengths so callers can reach it.
 * - Every change stores when and by whom, and that it came from the page.
 * - Dates (the spoken date, the last day) are YYYY-MM-DD in PBX time.
 *
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class RecordEditor
{
    public const int PAGE_SIZE = 50;

    public const int MAX_NOTE = 200;

    /** Up to this many records in one bulk change. */
    public const int MAX_BULK = 1000;

    public const array STALE_DAYS = [7, 30, 90];

    private const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}\z/';

    /**
     * @param Pin|null $pin Hashes new PINs; without it, a PIN can't be set.
     */
    public function __construct(private readonly PDO $db, private readonly DateTimeZone $timezone, private readonly ?Pin $pin = null)
    {
    }

    /**
     * One page of records, newest change first, filtered.
     *
     * @param array<string, mixed> $query search (ID digits, or text in the note), status (code), stale (days), page
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, today: string}
     */
    public function list(array $query, int $now): array
    {
        [$where, $params] = $this->filter($query, $now);
        $count = $this->db->prepare("SELECT COUNT(*) FROM m_StatusLookupRecords r $where");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / self::PAGE_SIZE));
        $page = min($pages, self::page($query['page'] ?? 1));
        $stmt = $this->db->prepare('SELECT r.id, r.lookup_id, r.status_code, r.note, r.status_date, r.expires_on, r.updated_at, r.updated_by,
                r.source, r.phone, r.pin_hash != \'\' AS has_pin FROM m_StatusLookupRecords r ' . $where . ' ORDER BY r.updated_at DESC, r.id DESC LIMIT ' . self::PAGE_SIZE
            . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE));
        $stmt->execute($params);

        return ['rows' => array_map([self::class, 'row'], $stmt->fetchAll(PDO::FETCH_ASSOC)), 'total' => $total,
            'page' => $page, 'pages' => $pages, 'today' => $this->today($now)];
    }

    /**
     * Adds a record (no id) or changes one (id). Returns its id.
     *
     * @param array<string, mixed> $input lookup_id, status_code, note, status_date, expires_on
     * @param array{int, int} $lengths The call flow's shortest and longest ID.
     * @param string $source How the change came: form (the page) or api.
     *
     * @throws EditorException
     */
    public function save(array $input, array $lengths, string $by, int $now, string $source = 'form'): int
    {
        $id = ($input['id'] ?? '') !== '' ? self::id($input['id']) : null;
        $lookupId = is_string($input['lookup_id'] ?? null) ? trim($input['lookup_id']) : '';
        [$min, $max] = $lengths;
        // A record kept when the ID lengths were narrowed can still be changed, as long as its ID stays as it is.
        $sameId = false;
        if ($id !== null && preg_match('/^[0-9]{1,20}\z/', $lookupId) === 1) {
            $stmt = $this->db->prepare('SELECT 1 FROM m_StatusLookupRecords WHERE id = ? AND lookup_id = ?');
            $stmt->execute([$id, $lookupId]);
            $sameId = $stmt->fetchColumn() !== false;
        }
        if (!$sameId && preg_match('/^[0-9]{' . $min . ',' . $max . '}\z/', $lookupId) !== 1) {
            throw new EditorException('lookup_id', ['min' => $min, 'max' => $max]);
        }
        $status = is_string($input['status_code'] ?? null) ? $input['status_code'] : '';
        $this->requireStatus($status);
        $note = is_string($input['note'] ?? null) ? trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $input['note'])) : '';
        if (mb_strlen($note) > self::MAX_NOTE) {
            throw new EditorException('note', ['max' => self::MAX_NOTE]);
        }
        $date = self::date($input['status_date'] ?? '', 'status_date');
        $expires = self::date($input['expires_on'] ?? '', 'expires_on');
        $phone = self::phone($input['phone'] ?? '');
        // A PIN is only ever written, as its hash: empty keeps the current one, remove_pin clears it.
        $newPin = is_string($input['pin'] ?? null) ? trim($input['pin']) : '';
        if ($newPin !== '' && (!Pin::isValid($newPin) || $this->pin === null)) {
            throw new EditorException('pin');
        }
        $pinHash = $newPin !== '' ? $this->pin->hash($newPin) : null;
        if ($pinHash === null && filter_var($input['remove_pin'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $pinHash = '';
        }

        $clash = $this->db->prepare('SELECT id FROM m_StatusLookupRecords WHERE lookup_id = ? AND id != ?');
        $clash->execute([$lookupId, $id ?? 0]);
        if ($clash->fetchColumn() !== false) {
            throw new EditorException('lookup_id_taken', ['id' => $lookupId]);
        }
        $values = [$lookupId, $status, $note, $date, $expires, $phone, $now, self::by($by), $source === 'api' ? 'api' : 'form'];
        if ($id === null) {
            $this->db->prepare('INSERT INTO m_StatusLookupRecords (lookup_id, status_code, note, status_date, expires_on, phone, updated_at,
                    updated_by, source, pin_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$values, $pinHash ?? '']);

            return (int)$this->db->lastInsertId();
        }
        $stmt = $this->db->prepare('UPDATE m_StatusLookupRecords SET lookup_id = ?, status_code = ?, note = ?, status_date = ?, expires_on = ?,
                phone = ?, updated_at = ?, updated_by = ?, source = ?, pin_hash = COALESCE(?, pin_hash) WHERE id = ?');
        $stmt->execute([...$values, $pinHash, $id]);
        if ($stmt->rowCount() === 0) {
            throw new EditorException('record_gone');
        }

        return $id;
    }

    /** How many records have IDs outside these lengths, so callers couldn't reach them. */
    public function countOutside(int $min, int $max): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM m_StatusLookupRecords WHERE LENGTH(lookup_id) < CAST(? AS INTEGER) OR LENGTH(lookup_id) > CAST(? AS INTEGER)');
        $stmt->execute([$min, $max]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Gives the chosen records another status.
     *
     * @param mixed $ids A list of record ids.
     *
     * @return int How many changed.
     *
     * @throws EditorException
     */
    public function changeStatus(mixed $ids, mixed $status, string $by, int $now): int
    {
        $list = self::ids($ids);
        $code = is_string($status) ? $status : '';
        $this->requireStatus($code);

        return $this->each($list, 'UPDATE m_StatusLookupRecords SET status_code = ?, updated_at = ?, updated_by = ?, source = \'form\' WHERE id = ?',
            static fn (int $id): array => [$code, $now, self::by($by), $id]);
    }

    /**
     * Deletes the chosen records.
     *
     * @return int How many were deleted.
     *
     * @throws EditorException
     */
    public function delete(mixed $ids): int
    {
        return $this->each(self::ids($ids), 'DELETE FROM m_StatusLookupRecords WHERE id = ?', static fn (int $id): array => [$id]);
    }

    /**
     * The filtered records as CSV, in the import format (lookup_id,
     * status_code, note, pin, expires, date, phone), so an export can be
     * edited in a spreadsheet and imported again. PINs are never exported
     * (the column stays empty, which the import reads as "keep the PIN"). A cell that a
     * spreadsheet would read as a formula gets a leading apostrophe.
     *
     * @param array<string, mixed> $query
     * @param callable(string): void $write Receives the CSV a line at a time.
     */
    public function export(array $query, int $now, callable $write): void
    {
        [$where, $params] = $this->filter($query, $now);
        $stmt = $this->db->prepare("SELECT r.lookup_id, r.status_code, r.note, r.expires_on, r.status_date, r.phone FROM m_StatusLookupRecords r $where
            ORDER BY r.lookup_id");
        $stmt->execute($params);
        $write(self::csvLine(['lookup_id', 'status_code', 'note', 'pin', 'expires', 'date', 'phone']));
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $write(self::csvLine([$row['lookup_id'], $row['status_code'], $row['note'], '', $row['expires_on'], $row['status_date'], $row['phone']]));
        }
    }

    /**
     * One CSV line. Text a spreadsheet would run as a formula (=, +, -, @,
     * tab, CR) is prefixed with '. (A spreadsheet may still drop an ID's
     * leading zeros when it opens the file; the import keeps whatever digits
     * the file holds.)
     *
     * @param list<mixed> $cells
     */
    public static function csvLine(array $cells): string
    {
        $out = [];
        foreach ($cells as $cell) {
            $text = (string)$cell;
            // Also a leading apostrophe before such a character, which the import would otherwise take as this guard.
            if ($text !== '' && (str_contains("=+-@\t\r", $text[0]) || preg_match("/^'[=+\\-@]/", $text) === 1)) {
                $text = "'" . $text;
            }
            $out[] = preg_match('/[",\r\n;]/', $text) === 1 || $text !== trim($text) ? '"' . str_replace('"', '""', $text) . '"' : $text;
        }

        return implode(',', $out) . "\r\n";
    }

    /** @return array{string, list<int|string>} */
    private function filter(array $query, int $now): array
    {
        $where = [];
        $params = [];
        $search = is_string($query['search'] ?? null) ? trim($query['search']) : '';
        if ($search !== '') {
            if (preg_match('/^[0-9]{1,20}\z/', $search) === 1) {
                // IDs: from the start, so 104 finds 10445.
                $where[] = 'r.lookup_id LIKE ?';
                $params[] = $search . '%';
            } else {
                $where[] = "r.note LIKE ? ESCAPE '\\'";
                $params[] = '%' . addcslashes(mb_substr($search, 0, 100), '%_\\') . '%';
            }
        }
        $status = is_string($query['status'] ?? null) ? $query['status'] : '';
        if ($status !== '') {
            if (preg_match(StatusEditor::CODE_PATTERN, $status) !== 1) {
                throw new EditorException('bad_request');
            }
            $where[] = 'r.status_code = ?';
            $params[] = $status;
        }
        $stale = (int)($query['stale'] ?? 0);
        if ($stale !== 0) {
            if (!in_array($stale, self::STALE_DAYS, true)) {
                throw new EditorException('bad_request');
            }
            $where[] = '(r.updated_at IS NULL OR r.updated_at < ?)';
            $params[] = $now - $stale * 86400;
        }
        if (($query['expired'] ?? '') === '1') {
            $where[] = "r.expires_on != '' AND r.expires_on < ?";
            $params[] = $this->today($now);
        }

        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $params];
    }

    /** @return array<string, mixed> */
    private static function row(array $r): array
    {
        return [
            'id'          => (int)$r['id'],
            'lookup_id'   => (string)$r['lookup_id'],
            'status_code' => (string)$r['status_code'],
            'note'        => (string)$r['note'],
            'status_date' => (string)$r['status_date'],
            'expires_on'  => (string)$r['expires_on'],
            'updated_at'  => $r['updated_at'] === null ? null : (int)$r['updated_at'],
            'updated_by'  => (string)$r['updated_by'],
            'source'      => (string)$r['source'],
            'phone'       => (string)$r['phone'],
            // Whether a PIN is set; the PIN itself is never stored or sent.
            'has_pin'     => (int)$r['has_pin'] === 1,
        ];
    }

    private function requireStatus(string $code): void
    {
        $stmt = $this->db->prepare('SELECT 1 FROM m_StatusLookupStatuses WHERE code = ?');
        $stmt->execute([$code]);
        if ($stmt->fetchColumn() === false) {
            throw new EditorException('record_status');
        }
    }

    private function today(int $now): string
    {
        return (new DateTimeImmutable("@$now"))->setTimezone($this->timezone)->format('Y-m-d');
    }

    /**
     * @param list<int> $ids
     * @param callable(int): list<int|string> $params
     */
    private function each(array $ids, string $sql, callable $params): int
    {
        return Databases::immediate($this->db, function () use ($ids, $sql, $params): int {
            $stmt = $this->db->prepare($sql);
            $changed = 0;
            foreach ($ids as $id) {
                $stmt->execute($params($id));
                $changed += $stmt->rowCount();
            }

            return $changed;
        });
    }

    /** @return list<int> */
    private static function ids(mixed $ids): array
    {
        if (!is_array($ids) || $ids === [] || count($ids) > self::MAX_BULK) {
            throw new EditorException('bulk', ['max' => self::MAX_BULK]);
        }

        return array_values(array_unique(array_map([self::class, 'id'], $ids)));
    }

    private static function id(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}\z/', $value) === 1) {
            return (int)$value;
        }
        throw new EditorException('bad_request');
    }

    private static function page(mixed $value): int
    {
        return is_numeric($value) ? max(1, min(100000, (int)$value)) : 1;
    }

    /** A YYYY-MM-DD date that exists, or ''. */
    private static function date(mixed $value, string $field): string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            return '';
        }
        $parsed = preg_match(self::DATE_PATTERN, $text) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $text) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $text) {
            throw new EditorException('date', ['field' => $field]);
        }

        return $text;
    }

    /** A phone number as stored: digits only (a leading + and separators are dropped), up to 20, or ''. */
    public static function phone(mixed $value): string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            return '';
        }
        // At least the digits the caller-ID check compares; a shorter number could never match.
        $digits = (string)preg_replace('/\D+/', '', $text);
        if (preg_match('/^\+?[0-9 ()\-.]{1,40}\z/', $text) !== 1 || strlen($digits) < Lookup::PHONE_DIGITS || strlen($digits) > 20) {
            throw new EditorException('phone');
        }

        return $digits;
    }

    /** Who made a change, as stored: an admin login or an API key's name, at most 64 characters, no control characters. */
    private static function by(string $by): string
    {
        return mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $by)), 0, 64);
    }
}
