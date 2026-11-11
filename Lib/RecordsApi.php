<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * What the REST API does, for shop, POS or ticketing software that keeps
 * the records up to date: list and read records, create or change one by
 * its ID, delete one, import a CSV, and read the statuses and the activity
 * totals.
 *
 * Every check is the same as on the admin page (RecordEditor, Importer):
 * the API can't store anything the page couldn't. Records the API changes
 * are marked with source "api" and the caller's name (an API key's
 * description, an administrator's login, or "PBX" for scripts on the PBX
 * itself).
 *
 * Plain PHP and PDO, no core dependencies, so it is unit-tested without a
 * PBX; the REST controllers in Lib/RestAPI only wire it to the core.
 */
final class RecordsApi
{
    /** The fields PUT accepts; anything else is refused, so a misspelt field never goes unnoticed. */
    public const array PUT_FIELDS = ['status_code', 'note', 'status_date', 'expires_on', 'phone', 'pin', 'remove_pin'];

    /** The core limits a JSON body to 4 MiB; the CSV travels inside one. */
    public const int MAX_IMPORT_BYTES = 4 * 1024 * 1024;

    public function __construct(private readonly PDO $db, private readonly DateTimeZone $timezone, private readonly ?Pin $pin = null)
    {
    }

    /**
     * A page of records, newest change first (50 a page).
     *
     * @param array<string, mixed> $query search, status, expired ('1'), page
     *
     * @return array{records: list<array<string, mixed>>, pagination: array{page: int, pages: int, total: int, page_size: int}}
     *
     * @throws EditorException
     */
    public function list(array $query, int $now): array
    {
        $filters = [];
        foreach (['search', 'status', 'expired', 'page'] as $name) {
            $filters[$name] = is_scalar($query[$name] ?? null) ? (string)$query[$name] : '';
        }
        $page = $this->editor()->list($filters, $now);

        return [
            'records'    => array_map([$this, 'record'], $page['rows']),
            'pagination' => ['page' => $page['page'], 'pages' => $page['pages'], 'total' => $page['total'], 'page_size' => RecordEditor::PAGE_SIZE],
        ];
    }

    /**
     * One record, or null when there is none with this ID.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $lookupId): ?array
    {
        $row = $this->find($lookupId);

        return $row === null ? null : $this->record($row);
    }

    /**
     * Creates the record with this ID, or changes it. Fields left out keep
     * their value (a new record needs status_code); an empty string clears
     * a field; a PIN is only ever replaced (pin) or removed (remove_pin).
     *
     * @param array<string, mixed> $data
     *
     * @return array{record: array<string, mixed>, created: bool}
     *
     * @throws EditorException
     */
    public function put(string $lookupId, array $data, string $by, int $now): array
    {
        $unknown = array_diff(array_keys($data), self::PUT_FIELDS);
        if ($unknown !== []) {
            throw new EditorException('unknown_fields', ['fields' => implode(',', array_map('strval', $unknown))]);
        }
        foreach ($data as $field => $value) {
            if (!(is_string($value) || is_null($value) || ($field === 'remove_pin' && is_bool($value)))) {
                throw new EditorException('field_type', ['field' => $field]);
            }
        }
        // The PBX's REST layer treats any text starting with "http" as a web address and strips its spaces and
        // non-Latin letters before the module sees it; refused rather than stored damaged.
        if (is_string($data['note'] ?? null) && stripos($data['note'], 'http') === 0) {
            throw new EditorException('note_http');
        }
        $lengths = Lookup::idLengths(Settings::load($this->db));

        return Databases::immediate($this->db, function () use ($lookupId, $data, $by, $now, $lengths): array {
            $existing = $this->find($lookupId);
            if ($existing === null && !isset($data['status_code'])) {
                throw new EditorException('status_required');
            }
            $input = ['lookup_id' => $lookupId];
            foreach (['status_code', 'note', 'status_date', 'expires_on', 'phone'] as $field) {
                $input[$field] = array_key_exists($field, $data) ? (string)$data[$field] : (string)($existing[$field] ?? '');
            }
            $input['pin'] = isset($data['pin']) ? (string)$data['pin'] : '';
            $input['remove_pin'] = $data['remove_pin'] ?? false;
            if ($existing !== null) {
                $input['id'] = (string)$existing['id'];
            }
            $this->editor()->save($input, $lengths, $by, $now, 'api');

            return ['record' => $this->record((array)$this->find($lookupId)), 'created' => $existing === null];
        });
    }

    /** Deletes the record with this ID; false when there was none. */
    public function delete(string $lookupId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM m_StatusLookupRecords WHERE lookup_id = ?');
        $stmt->execute([$lookupId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Imports a CSV in the import format: "update" adds and changes,
     * "replace" also deletes records not in the file. With $dryRun, only
     * says what would happen.
     *
     * @return array<string, mixed> The import's counts and the rows left out, as on the Import tab.
     *
     * @throws EditorException
     */
    public function import(mixed $csv, string $mode, bool $dryRun, string $by, int $now): array
    {
        // A CSV starting with "http" would arrive damaged (see put()); a real one starts with its header row.
        if (is_string($csv) && stripos($csv, 'http') === 0) {
            throw new EditorException('file_header');
        }
        if (!is_string($csv) || $csv === '') {
            throw new EditorException('file_empty');
        }
        if (strlen($csv) > self::MAX_IMPORT_BYTES) {
            throw new EditorException('file_size', ['max' => self::MAX_IMPORT_BYTES / 1024 / 1024]);
        }
        $statuses = $this->db->query('SELECT code FROM m_StatusLookupStatuses')->fetchAll(PDO::FETCH_COLUMN);
        $importer = new Importer($csv, Lookup::idLengths(Settings::load($this->db)), array_map('strval', $statuses), $this->pin);

        return ($dryRun ? $importer->preview($this->db, $mode) : $importer->apply($this->db, $mode, $by, 'API', $now, 'api')) + ['dry_run' => $dryRun];
    }

    /**
     * The statuses records can have, for integrators.
     *
     * @return list<array{code: string, name: string, records: int, requires_pin: bool, requires_callerid: bool}>
     */
    public function statuses(): array
    {
        return array_map(static fn (array $s): array => [
            'code' => $s['code'], 'name' => $s['name'], 'records' => $s['records'],
            'requires_pin' => $s['requires_pin'], 'requires_callerid' => $s['requires_callerid'],
        ], (new StatusEditor($this->db, static fn (int $id): bool => false, []))->list());
    }

    /**
     * Activity totals for a period of PBX days (YYYY-MM-DD), as on the
     * Activity tab, without the call log or the IDs callers typed.
     *
     * @return array<string, mixed>
     *
     * @throws EditorException
     */
    public function stats(mixed $from, mixed $to): array
    {
        $summary = (new Activity($this->db, $this->timezone))->summary(is_string($from) ? $from : '', is_string($to) ? $to : '');
        unset($summary['notFound']);

        return $summary;
    }

    private function editor(): RecordEditor
    {
        return new RecordEditor($this->db, $this->timezone, $this->pin);
    }

    /** @return array<string, mixed>|null */
    private function find(string $lookupId): ?array
    {
        $stmt = $this->db->prepare("SELECT id, lookup_id, status_code, note, status_date, expires_on, phone, updated_at, updated_by, source,
                pin_hash != '' AS has_pin FROM m_StatusLookupRecords WHERE lookup_id = ?");
        $stmt->execute([$lookupId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * A record as the API shows it: without the page's internal row id,
     * with the time of the last change in ISO 8601 (PBX time zone).
     *
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function record(array $r): array
    {
        $at = $r['updated_at'] ?? null;

        return [
            'lookup_id'   => (string)$r['lookup_id'],
            'status_code' => (string)$r['status_code'],
            'note'        => (string)$r['note'],
            'status_date' => (string)$r['status_date'],
            'expires_on'  => (string)$r['expires_on'],
            'phone'       => (string)$r['phone'],
            'has_pin'     => (bool)$r['has_pin'],
            'updated_at'  => $at === null || $at === '' ? null : (new DateTimeImmutable('@' . (int)$at))->setTimezone($this->timezone)->format(DATE_ATOM),
            'updated_by'  => (string)$r['updated_by'],
            'source'      => (string)$r['source'],
        ];
    }
}
