<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * CSV import of records, with a dry run.
 *
 * The file: UTF-8 (a byte-order mark is fine), comma- or semicolon-separated,
 * with a header row. lookup_id and status_code are required; note, pin,
 * expires and date are optional, in any order. Export CSV writes the same
 * format.
 *
 * Each row is checked on its own and rejected with its row number (the
 * header is row 1; a quoted value may span lines) and a reason, never
 * silently changed: an ID with other characters than digits
 * keeps only its digits (as typed on a keypad), and must then fit the call
 * flow's ID lengths; status codes are matched without regard to case; a
 * second row with the same ID is rejected. Dates are YYYY-MM-DD. PINs are
 * not supported yet, so a row with one is rejected rather than stored
 * without it.
 *
 * Modes: update adds new IDs and changes existing ones; replace also deletes
 * every record the file doesn't have. Applying writes everything in one
 * transaction: a call during the import sees the records as they were
 * before or after it, never half of it.
 *
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class Importer
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    public const int MAX_ROWS = 100000;

    /** Rejected rows kept in the preview and the history. */
    public const int MAX_REPORT = 500;

    public const array MODES = ['update', 'replace'];

    /** Column names the header may use, and the field each one fills. */
    private const array COLUMNS = [
        'lookup_id' => 'lookup_id', 'id' => 'lookup_id',
        'status_code' => 'status_code', 'status' => 'status_code',
        'note' => 'note',
        'pin' => 'pin',
        'expires' => 'expires_on', 'expires_on' => 'expires_on',
        'date' => 'status_date', 'status_date' => 'status_date',
    ];

    /** @var array<string, array{lookup_id: string, status_code: string, note: string, expires_on: string, status_date: string}> */
    private array $rows = [];

    /** @var list<array{row: int, reason: string, id: string}> */
    private array $rejected = [];

    private int $rejectedCount = 0;

    /**
     * Reads and checks a file. Throws when the file as a whole can't be used
     * (size, encoding, header, row count); bad rows are collected instead.
     *
     * @param array{int, int} $lengths The call flow's shortest and longest ID.
     * @param list<string> $statusCodes The statuses that exist.
     *
     * @throws EditorException
     */
    public function __construct(string $csv, array $lengths, array $statusCodes)
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw new EditorException('file_size', ['max' => self::MAX_BYTES / 1024 / 1024]);
        }
        $csv = (string)preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        if (!mb_check_encoding($csv, 'UTF-8')) {
            throw new EditorException('file_encoding');
        }
        // The first line decides the separator; fgetcsv() then reads quoted values over several lines.
        $firstLine = strtok($csv, "\r\n");
        if ($firstLine === false || trim($firstLine) === '') {
            throw new EditorException('file_empty');
        }
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $columns = $this->header(fgetcsv($stream, null, $delimiter, '"', '') ?: []);

        [$min, $max] = $lengths;
        $statuses = [];
        foreach ($statusCodes as $code) {
            $statuses[strtoupper($code)] = $code;
        }
        $seen = [];
        $rowNo = 1;
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $rowNo++;
            if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                continue;
            }
            if ($rowNo - 1 > self::MAX_ROWS) {
                fclose($stream);
                throw new EditorException('file_rows', ['max' => self::MAX_ROWS]);
            }
            $row = [];
            foreach ($columns as $position => $field) {
                $row[$field] = trim((string)($cells[$position] ?? ''));
            }
            $id = (string)preg_replace('/\D+/', '', $row['lookup_id'] ?? '');
            $reason = match (true) {
                $id === '' => 'no_id',
                strlen($id) < $min || strlen($id) > $max => 'id_length',
                isset($seen[$id]) => 'duplicate',
                !isset($statuses[strtoupper($row['status_code'] ?? '')]) => 'status',
                mb_strlen($row['note'] ?? '') > RecordEditor::MAX_NOTE => 'note',
                ($row['pin'] ?? '') !== '' => 'pin',
                !self::isDate($row['expires_on'] ?? '') => 'expires',
                !self::isDate($row['status_date'] ?? '') => 'date',
                default => null,
            };
            if ($reason !== null) {
                $this->reject($rowNo, $reason, $reason === 'duplicate' ? "$id ({$seen[$id]})" : ($id !== '' ? $id : mb_substr($row['lookup_id'] ?? '', 0, 20)));
                continue;
            }
            $seen[$id] = $rowNo;
            $this->rows[$id] = [
                'lookup_id'   => $id,
                'status_code' => $statuses[strtoupper($row['status_code'])],
                'note'        => self::note($row['note'] ?? ''),
                'expires_on'  => $row['expires_on'] ?? '',
                'status_date' => $row['status_date'] ?? '',
            ];
        }
        fclose($stream);
    }

    /**
     * What applying the file would do, without changing anything.
     *
     * @return array{added: int, updated: int, unchanged: int, deleted: int, rejected: int, report: list<array{row: int, reason: string, id: string}>}
     */
    public function preview(PDO $db, string $mode): array
    {
        self::requireMode($mode);
        $existing = $this->existing($db);
        $counts = ['added' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach ($this->rows as $id => $row) {
            $counts[!isset($existing[$id]) ? 'added' : ($existing[$id] === self::fingerprint($row) ? 'unchanged' : 'updated')]++;
        }
        $deleted = $mode === 'replace' ? count(array_diff_key($existing, $this->rows)) : 0;

        return $counts + ['deleted' => $deleted, 'rejected' => $this->rejectedCount, 'report' => $this->rejected];
    }

    /**
     * Applies the file in one transaction and records it in the history.
     * Replacing with a file that has no usable row is refused: it would
     * delete every record.
     *
     * @return array{added: int, updated: int, unchanged: int, deleted: int, rejected: int, report: list<array{row: int, reason: string, id: string}>}
     */
    public function apply(PDO $db, string $mode, string $by, string $fileName, int $now): array
    {
        $result = $this->preview($db, $mode);
        if ($mode === 'replace' && $this->rows === []) {
            throw new EditorException('replace_empty');
        }
        $by = mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $by)), 0, 64);
        $fileName = mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $fileName)), 0, 120);
        $db->beginTransaction();
        try {
            $existing = $this->existing($db);
            $insert = $db->prepare("INSERT INTO m_StatusLookupRecords (lookup_id, status_code, note, expires_on, status_date, updated_at, updated_by,
                    source, pin_hash, phone) VALUES (?, ?, ?, ?, ?, ?, ?, 'csv', '', '')");
            $update = $db->prepare("UPDATE m_StatusLookupRecords SET status_code = ?, note = ?, expires_on = ?, status_date = ?, updated_at = ?,
                    updated_by = ?, source = 'csv' WHERE lookup_id = ?");
            foreach ($this->rows as $id => $row) {
                if (!isset($existing[$id])) {
                    $insert->execute([$id, $row['status_code'], $row['note'], $row['expires_on'], $row['status_date'], $now, $by]);
                } elseif ($existing[$id] !== self::fingerprint($row)) {
                    $update->execute([$row['status_code'], $row['note'], $row['expires_on'], $row['status_date'], $now, $by, $id]);
                }
            }
            if ($mode === 'replace') {
                $delete = $db->prepare('DELETE FROM m_StatusLookupRecords WHERE lookup_id = ?');
                foreach (array_keys(array_diff_key($existing, $this->rows)) as $id) {
                    $delete->execute([(string)$id]);
                }
            }
            $db->prepare('INSERT INTO m_StatusLookupImports (imported_at, imported_by, file_name, mode, added, updated, unchanged, deleted, rejected, report)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$now, $by, $fileName, $mode, $result['added'], $result['updated'], $result['unchanged'], $result['deleted'],
                    $result['rejected'], json_encode($result['report'], JSON_THROW_ON_ERROR)]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $result;
    }

    /**
     * The import history, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function history(PDO $db, int $limit = 20): array
    {
        $rows = $db->query('SELECT id, imported_at, imported_by, file_name, mode, added, updated, unchanged, deleted, rejected, report
            FROM m_StatusLookupImports ORDER BY imported_at DESC, id DESC LIMIT ' . max(1, min(100, $limit)))->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $r): array {
            $report = json_decode((string)$r['report'], true);

            return [
                'id' => (int)$r['id'], 'imported_at' => (int)$r['imported_at'], 'imported_by' => (string)$r['imported_by'],
                'file_name' => (string)$r['file_name'], 'mode' => (string)$r['mode'], 'added' => (int)$r['added'],
                'updated' => (int)$r['updated'], 'unchanged' => (int)$r['unchanged'], 'deleted' => (int)$r['deleted'],
                'rejected' => (int)$r['rejected'], 'report' => is_array($report) ? $report : [],
            ];
        }, $rows);
    }

    /**
     * @param list<string|null> $cells
     *
     * @return array<int, string> Column position => field.
     */
    private function header(array $cells): array
    {
        $columns = [];
        foreach ($cells as $position => $cell) {
            $name = strtolower(trim((string)$cell));
            if (isset(self::COLUMNS[$name]) && !in_array(self::COLUMNS[$name], $columns, true)) {
                $columns[$position] = self::COLUMNS[$name];
            }
        }
        if (!in_array('lookup_id', $columns, true) || !in_array('status_code', $columns, true)) {
            throw new EditorException('file_header');
        }

        return $columns;
    }

    private function reject(int $line, string $reason, string $id): void
    {
        $this->rejectedCount++;
        if (count($this->rejected) < self::MAX_REPORT) {
            $this->rejected[] = ['row' => $line, 'reason' => $reason, 'id' => $id];
        }
    }

    /** @return array<string, string> Lookup ID => fingerprint of what the import can change. */
    private function existing(PDO $db): array
    {
        $out = [];
        foreach ($db->query('SELECT lookup_id, status_code, note, expires_on, status_date FROM m_StatusLookupRecords')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['lookup_id']] = self::fingerprint($r);
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private static function fingerprint(array $row): string
    {
        return json_encode([(string)$row['status_code'], (string)$row['note'], (string)$row['expires_on'], (string)$row['status_date']],
            JSON_THROW_ON_ERROR);
    }

    /**
     * A note as stored: one line, and without the apostrophe Export CSV puts
     * before text a spreadsheet would run as a formula, so an exported file
     * imports back unchanged.
     */
    private static function note(string $text): string
    {
        $text = trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));

        return preg_match("/^'[=+\\-@]/", $text) === 1 ? substr($text, 1) : $text;
    }

    private static function isDate(string $text): bool
    {
        if ($text === '') {
            return true;
        }
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}\z/', $text) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $text) : false;

        return $parsed !== false && $parsed->format('Y-m-d') === $text;
    }

    private static function requireMode(string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new EditorException('bad_request');
        }
    }
}
