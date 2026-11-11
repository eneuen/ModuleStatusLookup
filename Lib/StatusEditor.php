<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use PDO;
use Throwable;

/**
 * The statuses records can have: list, add, change, reorder and delete.
 *
 * - A code is what CSV files and the API use (A-Z, 0-9 and _). Changing a
 *   status's code changes it on its records too, in one transaction.
 * - A status records still use can't simply be deleted: its records move to
 *   another status first, in the same transaction.
 * - A status's prompt is one of the PBX's sound files: the sentence is
 *   specific to the business, so the built-in prompts are not offered.
 *
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class StatusEditor
{
    public const string CODE_PATTERN = '/^[A-Z0-9_]{1,32}\z/';

    public const int MAX_NAME = 60;

    public const int MAX_STATUSES = 50;

    public const int MAX_SCRIPT = 300;

    /**
     * @param callable(int): bool $promptUsable Whether a PBX sound file ID can be played.
     * @param list<string> $destinations The numbers callers may be sent to.
     */
    public function __construct(private readonly PDO $db, private $promptUsable, private readonly array $destinations)
    {
    }

    /**
     * Every status in order, with how many records have it.
     *
     * @return list<array<string, bool|int|string|null>>
     */
    public function list(): array
    {
        $rows = $this->db->query('SELECT s.id, s.code, s.name, s.script, s.prompt_id, s.spoken_extra, s.after_action, s.transfer_number,
                s.requires_pin, s.requires_callerid,
                s.sort_order, (SELECT COUNT(*) FROM m_StatusLookupRecords r WHERE r.status_code = s.code) AS records
            FROM m_StatusLookupStatuses s ORDER BY s.sort_order, s.id')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => [
            'id'              => (int)$r['id'],
            'code'            => (string)$r['code'],
            'name'            => (string)$r['name'],
            'script'          => (string)$r['script'],
            'prompt_id'       => $r['prompt_id'] === null ? null : (int)$r['prompt_id'],
            'spoken_extra'    => (string)$r['spoken_extra'],
            'after_action'    => (string)$r['after_action'],
            'transfer_number' => (string)$r['transfer_number'],
            'requires_pin'    => (string)$r['requires_pin'] === '1',
            'requires_callerid' => (string)$r['requires_callerid'] === '1',
            'records'         => (int)$r['records'],
        ], $rows);
    }

    /**
     * Adds a status (no id) or changes one (id). Returns its id.
     *
     * @param array<string, mixed> $input
     *
     * @throws EditorException
     */
    public function save(array $input): int
    {
        $id = ($input['id'] ?? '') !== '' ? self::id($input['id']) : null;
        $existing = $id === null ? null : $this->find($id);
        if ($id !== null && $existing === null) {
            throw new EditorException('status_gone');
        }
        $values = $this->validate($input);
        $clash = $this->db->prepare('SELECT id FROM m_StatusLookupStatuses WHERE code = ? AND id != ?');
        $clash->execute([$values['code'], $id ?? 0]);
        if ($clash->fetchColumn() !== false) {
            throw new EditorException('code_taken', ['code' => $values['code']]);
        }

        return $this->transaction(function () use ($id, $existing, $values): int {
            if ($id === null) {
                if ((int)$this->db->query('SELECT COUNT(*) FROM m_StatusLookupStatuses')->fetchColumn() >= self::MAX_STATUSES) {
                    throw new EditorException('too_many_statuses', ['max' => self::MAX_STATUSES]);
                }
                $order = (int)$this->db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM m_StatusLookupStatuses')->fetchColumn();
                $this->db->prepare('INSERT INTO m_StatusLookupStatuses (code, name, script, prompt_id, spoken_extra, after_action, transfer_number,
                        requires_pin, requires_callerid, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([...array_values($values), $order]);

                return (int)$this->db->lastInsertId();
            }
            $this->db->prepare('UPDATE m_StatusLookupStatuses SET code = ?, name = ?, script = ?, prompt_id = ?, spoken_extra = ?, after_action = ?,
                    transfer_number = ?, requires_pin = ?, requires_callerid = ? WHERE id = ?')->execute([...array_values($values), $id]);
            if ($existing['code'] !== $values['code']) {
                $this->db->prepare('UPDATE m_StatusLookupRecords SET status_code = ? WHERE status_code = ?')
                    ->execute([$values['code'], $existing['code']]);
            }

            return $id;
        });
    }

    /**
     * Deletes a status. Records that have it move to $moveTo first; without
     * one, a status records still use is refused.
     *
     * @return int How many records moved.
     *
     * @throws EditorException
     */
    public function delete(mixed $idInput, mixed $moveToInput = null): int
    {
        $status = $this->find(self::id($idInput));
        if ($status === null) {
            throw new EditorException('status_gone');
        }
        $target = null;
        if ($moveToInput !== null && $moveToInput !== '') {
            $target = $this->find(self::id($moveToInput));
            if ($target === null || $target['id'] === $status['id']) {
                throw new EditorException('move_to');
            }
        }

        return $this->transaction(function () use ($status, $target): int {
            $used = $this->db->prepare('SELECT COUNT(*) FROM m_StatusLookupRecords WHERE status_code = ?');
            $used->execute([$status['code']]);
            $records = (int)$used->fetchColumn();
            if ($records > 0 && $target === null) {
                throw new EditorException('status_in_use', ['n' => $records]);
            }
            if ($records > 0) {
                $this->db->prepare('UPDATE m_StatusLookupRecords SET status_code = ? WHERE status_code = ?')
                    ->execute([$target['code'], $status['code']]);
            }
            $this->db->prepare('DELETE FROM m_StatusLookupStatuses WHERE id = ?')->execute([$status['id']]);

            return $records;
        });
    }

    /**
     * Moves a status one place up (-1) or down (+1), renumbering them all.
     *
     * @throws EditorException
     */
    public function move(mixed $idInput, int $step): void
    {
        $id = self::id($idInput);
        if ($step === 0) {
            return;
        }
        $ids = array_map('intval', $this->db->query('SELECT id FROM m_StatusLookupStatuses ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN));
        $at = array_search($id, $ids, true);
        if ($at === false) {
            throw new EditorException('status_gone');
        }
        $to = $at + ($step < 0 ? -1 : 1);
        if ($to < 0 || $to >= count($ids)) {
            return;
        }
        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        $this->transaction(function () use ($ids): void {
            $stmt = $this->db->prepare('UPDATE m_StatusLookupStatuses SET sort_order = ? WHERE id = ?');
            foreach ($ids as $order => $statusId) {
                $stmt->execute([$order + 1, $statusId]);
            }
        });
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{code: string, name: string, script: string, prompt_id: int, spoken_extra: string, after_action: string, transfer_number: string, requires_pin: string, requires_callerid: string}
     *
     * @throws EditorException
     */
    private function validate(array $input): array
    {
        $code = is_string($input['code'] ?? null) ? strtoupper(trim($input['code'])) : '';
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new EditorException('code_format');
        }
        $name = is_string($input['name'] ?? null) ? trim((string)preg_replace('/\s+/u', ' ', $input['name'])) : '';
        if ($name === '' || mb_strlen($name) > self::MAX_NAME || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new EditorException('name', ['max' => self::MAX_NAME]);
        }
        $script = is_string($input['script'] ?? null) ? trim((string)preg_replace('/\s+/u', ' ', $input['script'])) : '';
        if (mb_strlen($script) > self::MAX_SCRIPT || preg_match('/[\x00-\x1F\x7F]/', $script) === 1) {
            throw new EditorException('script', ['max' => self::MAX_SCRIPT]);
        }
        $prompt = $input['prompt_id'] ?? null;
        $promptId = is_int($prompt) || (is_string($prompt) && preg_match('/^[1-9][0-9]{0,8}\z/', $prompt) === 1) ? (int)$prompt : 0;
        if ($promptId <= 0 || !($this->promptUsable)($promptId)) {
            throw new EditorException('status_prompt');
        }
        $extra = is_string($input['spoken_extra'] ?? null) ? $input['spoken_extra'] : '';
        if (!in_array($extra, Lookup::EXTRAS, true)) {
            throw new EditorException('bad_request');
        }
        $action = is_string($input['after_action'] ?? null) ? $input['after_action'] : '';
        if (!in_array($action, Lookup::ACTIONS, true)) {
            throw new EditorException('bad_request');
        }
        $transfer = '';
        if ($action === 'transfer') {
            $transfer = is_scalar($input['transfer_number'] ?? null) ? trim((string)$input['transfer_number']) : '';
            if (!in_array($transfer, $this->destinations, true)) {
                throw new EditorException('status_destination');
            }
        }

        return ['code' => $code, 'name' => $name, 'script' => $script, 'prompt_id' => $promptId, 'spoken_extra' => $extra,
            'after_action' => $action, 'transfer_number' => $transfer,
            'requires_pin' => filter_var($input['requires_pin'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'requires_callerid' => filter_var($input['requires_callerid'] ?? false, FILTER_VALIDATE_BOOLEAN) ? '1' : '0'];
    }

    /** @return array{id: int, code: string}|null */
    private function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, code FROM m_StatusLookupStatuses WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['id' => (int)$row['id'], 'code' => (string)$row['code']];
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

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        return Databases::immediate($this->db, $work);
    }
}
