<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Writes the call log and the daily counts. Only the worker writes them, one
 * record at a time, so there is a single writer.
 *
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class CallLogStore
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Stores one call (a Handoff record). Test calls go to the log, flagged,
     * but not into the daily counts. A record already stored (the same visit:
     * linkedid and start time) is dropped: the dialplan hands a call off again
     * when the caller hung up during the first hand-off.
     *
     * @param array<string, string> $record
     *
     * @return bool Whether it was stored.
     */
    public function record(array $record, DateTimeZone $timezone): bool
    {
        $seen = $this->db->prepare('SELECT 1 FROM m_StatusLookupCallLog WHERE linkedid = ? AND started_at = ?');
        $seen->execute([$record['linkedid'], (int)$record['started_at']]);
        if ($seen->fetchColumn() !== false) {
            return false;
        }
        $toPerson = $record['target'] !== '' ? '1' : '0';
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO m_StatusLookupCallLog (linkedid, started_at, ended_at, caller, id_entered, tries, misses,
                    outcome, last_result, status_code, target, is_test) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $record['linkedid'], (int)$record['started_at'], (int)$record['ended_at'], $record['caller'],
                    $record['id_entered'], (int)$record['tries'], (int)$record['misses'], $record['outcome'],
                    $record['last_result'], $record['status_code'], $record['target'], $record['is_test'] === '1' ? '1' : '0',
                ]);
            if ($record['is_test'] !== '1') {
                $day = (new DateTimeImmutable('@' . (int)$record['started_at']))->setTimezone($timezone)->format('Y-m-d');
                $this->db->prepare('INSERT INTO m_StatusLookupDailyCounts (day, outcome, to_person, calls, misses) VALUES (?, ?, ?, 1, ?)
                        ON CONFLICT (day, outcome, to_person) DO UPDATE SET calls = calls + 1, misses = misses + excluded.misses')
                    ->execute([$day, $record['outcome'], $toPerson, (int)$record['misses']]);
            }
            $this->db->commit();

            return true;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
