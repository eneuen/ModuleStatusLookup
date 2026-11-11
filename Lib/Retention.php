<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * What the module keeps, and for how long.
 *
 * - The call log holds callers' numbers and the IDs they typed, so it is
 *   kept only as long as the PBX keeps its call history (its "keep call
 *   recordings" period; under 30 days means for ever, as in the core), or
 *   for the module's own number of days when one is set. Old calls are
 *   deleted by whole PBX day. The daily counts have no numbers and stay.
 * - Records are the business's own data and stay until staff or the API
 *   delete them; expired ones can be purged every night (off by default).
 * - Forget: one ID (its record and every call that typed it), or one
 *   caller number (its calls, and the number on records).
 *
 * The worker runs the nightly part; the page runs Forget.
 *
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class Retention
{
    public const int MAX_DAYS = 3650;

    /** Below this, the PBX's call-history period means "keep for ever" (as in the core). */
    public const int CDR_MINIMUM = 30;

    public function __construct(private readonly PDO $db, private readonly DateTimeZone $timezone)
    {
    }

    /**
     * Days the call log is kept; 0 is for ever.
     *
     * @param int $ownDays The module's own setting; 0 follows the PBX.
     * @param int $cdrDays The PBX's call-history period.
     */
    public static function logDays(int $ownDays, int $cdrDays): int
    {
        if ($ownDays > 0) {
            return min($ownDays, self::MAX_DAYS);
        }

        return $cdrDays >= self::CDR_MINIMUM ? $cdrDays : 0;
    }

    /**
     * The nightly clean-up: calls older than $days whole days (none when
     * $days is 0), and expired records when $purgeExpired.
     *
     * @return array{calls: int, records: int} How many were deleted.
     */
    public function run(int $now, int $days, bool $purgeExpired): array
    {
        $today = (new DateTimeImmutable('@' . $now))->setTimezone($this->timezone)->setTime(0, 0);
        $deleted = ['calls' => 0, 'records' => 0];
        Databases::immediate($this->db, function () use ($today, $days, $purgeExpired, &$deleted): void {
            if ($days > 0) {
                // Today and the $days - 1 days before it stay.
                $cutoff = $today->sub(new DateInterval('P' . ($days - 1) . 'D'))->getTimestamp();
                $stmt = $this->db->prepare('DELETE FROM m_StatusLookupCallLog WHERE started_at < ?');
                $stmt->execute([$cutoff]);
                $deleted['calls'] = $stmt->rowCount();
            }
            if ($purgeExpired) {
                // The last day itself still counts; only well-formed dates, as a malformed one may be a typo staff will fix.
                $stmt = $this->db->prepare("DELETE FROM m_StatusLookupRecords
                    WHERE expires_on GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]' AND expires_on < ?");
                $stmt->execute([$today->format('Y-m-d')]);
                $deleted['records'] = $stmt->rowCount();
            }
        });

        return $deleted;
    }

    /**
     * Deletes one ID's record and every call that typed it.
     *
     * @return array{records: int, calls: int}
     *
     * @throws EditorException
     */
    public function forgetId(mixed $id): array
    {
        $id = is_string($id) ? Lookup::digits($id) : '';
        if ($id === '' || strlen($id) > 20) {
            throw new EditorException('forget_id');
        }
        $deleted = ['records' => 0, 'calls' => 0];
        Databases::immediate($this->db, function () use ($id, &$deleted): void {
            $stmt = $this->db->prepare('DELETE FROM m_StatusLookupRecords WHERE lookup_id = ?');
            $stmt->execute([$id]);
            $deleted['records'] = $stmt->rowCount();
            $stmt = $this->db->prepare('DELETE FROM m_StatusLookupCallLog WHERE id_entered = ?');
            $stmt->execute([$id]);
            $deleted['calls'] = $stmt->rowCount();
        });

        return $deleted;
    }

    /**
     * Deletes every call from one caller number and removes it from
     * records. The number matches as for the caller-ID check (the same last
     * digits), so +49 151 12345678 also finds 015112345678.
     *
     * @return array{calls: int, records: int}
     *
     * @throws EditorException
     */
    public function forgetCaller(mixed $phone): array
    {
        $phone = is_string($phone) ? Lookup::digits($phone) : '';
        if (strlen($phone) < Lookup::PHONE_DIGITS || strlen($phone) > 20) {
            throw new EditorException('forget_phone', ['min' => Lookup::PHONE_DIGITS]);
        }
        $tail = substr($phone, -Lookup::PHONE_DIGITS);
        $deleted = ['calls' => 0, 'records' => 0];
        Databases::immediate($this->db, function () use ($phone, $tail, &$deleted): void {
            // Narrowed by the last digits in SQL, then matched exactly as the lookup does.
            foreach (['m_StatusLookupCallLog' => 'caller', 'm_StatusLookupRecords' => 'phone'] as $table => $column) {
                $stmt = $this->db->prepare("SELECT id, $column FROM $table WHERE substr($column, -" . Lookup::PHONE_DIGITS . ') = ?');
                $stmt->execute([$tail]);
                $ids = [];
                foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$rowId, $number]) {
                    if (Lookup::samePhone((string)$number, $phone)) {
                        $ids[] = (int)$rowId;
                    }
                }
                foreach (array_chunk($ids, 500) as $chunk) {
                    $marks = implode(',', array_fill(0, count($chunk), '?'));
                    $sql = $table === 'm_StatusLookupCallLog'
                        ? "DELETE FROM $table WHERE id IN ($marks)"
                        : "UPDATE $table SET phone = '' WHERE id IN ($marks)";
                    $this->db->prepare($sql)->execute($chunk);
                }
                $deleted[$table === 'm_StatusLookupCallLog' ? 'calls' : 'records'] = count($ids);
            }
        });

        return $deleted;
    }
}
