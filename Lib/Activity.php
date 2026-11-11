<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * What the Activity tab shows for a period: how many calls the line took,
 * how many heard a status, how many of those ended without reaching a person
 * (deflected), where callers went instead, the IDs most often not found, the
 * busiest hours, and the call log.
 *
 * Totals, the trend and outcomes come from the daily counts, which survive
 * the call log's retention; the top IDs, the hours and the call log come
 * from the call log itself. Test calls are left out of everything but the
 * call log, where they are marked. Days are PBX days.
 *
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class Activity
{
    public const int MAX_DAYS = 366;

    public const int LOG_PAGE_SIZE = 50;

    public const int TOP_IDS = 10;

    /** Outcomes in the order they are listed when their counts are equal. */
    public const array OUTCOME_ORDER = ['hit', 'hit_person', 'operator', 'too_many', 'rate_limited', 'fault', 'abandoned', 'inactive'];

    public function __construct(private readonly PDO $db, private readonly DateTimeZone $timezone)
    {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws EditorException
     */
    public function summary(string $from, string $to): array
    {
        [$start, $end, $days] = $this->range($from, $to);
        $previousEnd = $start->sub(new DateInterval('P1D'));
        $previousStart = $previousEnd->sub(new DateInterval('P' . ($days - 1) . 'D'));

        $trend = [];
        for ($day = $start; $day <= $end; $day = $day->add(new DateInterval('P1D'))) {
            $trend[$day->format('Y-m-d')] = ['day' => $day->format('Y-m-d'), 'calls' => 0, 'hits' => 0, 'deflected' => 0];
        }
        $outcomes = [];
        foreach ($this->counts($start, $end) as $row) {
            $calls = (int)$row['calls'];
            $trend[$row['day']]['calls'] += $calls;
            $key = $row['outcome'] === 'hit' ? ($row['to_person'] === '1' ? 'hit_person' : 'hit') : (string)$row['outcome'];
            $outcomes[$key] = ($outcomes[$key] ?? 0) + $calls;
            if ($row['outcome'] === 'hit') {
                $trend[$row['day']]['hits'] += $calls;
                if ($row['to_person'] !== '1') {
                    $trend[$row['day']]['deflected'] += $calls;
                }
            }
        }
        // Most calls first; ties in a fixed order, so the chart doesn't reshuffle.
        $rank = array_flip(self::OUTCOME_ORDER);
        uksort($outcomes, static fn (string $a, string $b): int => [$outcomes[$b], $rank[$a] ?? 99] <=> [$outcomes[$a], $rank[$b] ?? 99]);

        [$startEpoch, $endEpoch] = $this->epochs($start, $end);

        return [
            'from'     => $start->format('Y-m-d'),
            'to'       => $end->format('Y-m-d'),
            'totals'   => $this->totals($start, $end),
            'previous' => $this->totals($previousStart, $previousEnd) + ['from' => $previousStart->format('Y-m-d'), 'to' => $previousEnd->format('Y-m-d')],
            'trend'    => array_values($trend),
            'outcomes' => array_map(static fn (string $k, int $n): array => ['outcome' => $k, 'calls' => $n], array_keys($outcomes), $outcomes),
            'notFound' => $this->topNotFound($startEpoch, $endEpoch),
            'hours'    => $this->hours($startEpoch, $endEpoch),
        ];
    }

    /**
     * One page of the call log for the period, newest first; outcome '' is all.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     *
     * @throws EditorException
     */
    public function log(string $from, string $to, string $outcome, mixed $page): array
    {
        [$start, $end] = $this->range($from, $to);
        [$startEpoch, $endEpoch] = $this->epochs($start, $end);
        if ($outcome !== '' && !in_array($outcome, Handoff::OUTCOMES, true)) {
            throw new EditorException('bad_request');
        }
        $where = 'started_at >= ? AND started_at < ?' . ($outcome === '' ? '' : ' AND outcome = ?');
        $params = $outcome === '' ? [$startEpoch, $endEpoch] : [$startEpoch, $endEpoch, $outcome];
        $count = $this->db->prepare("SELECT COUNT(*) FROM m_StatusLookupCallLog WHERE $where");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / self::LOG_PAGE_SIZE));
        $page = is_numeric($page) ? max(1, min($pages, (int)$page)) : 1;
        $stmt = $this->db->prepare("SELECT started_at, ended_at, caller, id_entered, tries, misses, outcome, last_result, status_code, target, is_test
            FROM m_StatusLookupCallLog WHERE $where ORDER BY started_at DESC, id DESC LIMIT " . self::LOG_PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::LOG_PAGE_SIZE));
        $stmt->execute($params);
        $rows = array_map(static fn (array $r): array => [
            'started_at' => (int)$r['started_at'], 'seconds' => max(0, (int)$r['ended_at'] - (int)$r['started_at']),
            'caller' => (string)$r['caller'], 'id_entered' => (string)$r['id_entered'], 'tries' => (int)$r['tries'],
            'misses' => (int)$r['misses'], 'outcome' => (string)$r['outcome'], 'last_result' => (string)$r['last_result'],
            'status_code' => (string)$r['status_code'], 'target' => (string)$r['target'], 'is_test' => $r['is_test'] === '1',
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return array{calls: int, hits: int, deflected: int, to_person: int, misses: int} */
    private function totals(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $totals = ['calls' => 0, 'hits' => 0, 'deflected' => 0, 'to_person' => 0, 'misses' => 0];
        foreach ($this->counts($start, $end) as $row) {
            $calls = (int)$row['calls'];
            $totals['calls'] += $calls;
            $totals['misses'] += (int)$row['misses'];
            if ($row['to_person'] === '1') {
                $totals['to_person'] += $calls;
            }
            if ($row['outcome'] === 'hit') {
                $totals['hits'] += $calls;
                if ($row['to_person'] !== '1') {
                    $totals['deflected'] += $calls;
                }
            }
        }

        return $totals;
    }

    /** @return list<array{day: string, outcome: string, to_person: string, calls: int|string, misses: int|string}> */
    private function counts(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $stmt = $this->db->prepare('SELECT day, outcome, to_person, calls, misses FROM m_StatusLookupDailyCounts WHERE day >= ? AND day <= ?');
        $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);

        return array_map(static fn (array $r): array => ['to_person' => (string)$r['to_person']] + $r, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{id: string, calls: int}> The IDs callers typed most that weren't found or had expired. */
    private function topNotFound(int $start, int $end): array
    {
        $stmt = $this->db->prepare("SELECT id_entered, COUNT(*) AS calls FROM m_StatusLookupCallLog
            WHERE started_at >= ? AND started_at < ? AND is_test = '0' AND id_entered != '' AND outcome != 'hit'
              AND last_result IN ('not_found', 'expired')
            GROUP BY id_entered ORDER BY calls DESC, id_entered LIMIT " . self::TOP_IDS);
        $stmt->execute([$start, $end]);

        return array_map(static fn (array $r): array => ['id' => (string)$r['id_entered'], 'calls' => (int)$r['calls']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<int> Calls in each hour of the day (0-23, PBX time). */
    private function hours(int $start, int $end): array
    {
        $hours = array_fill(0, 24, 0);
        $stmt = $this->db->prepare("SELECT started_at FROM m_StatusLookupCallLog WHERE started_at >= ? AND started_at < ? AND is_test = '0'");
        $stmt->execute([$start, $end]);
        while (($at = $stmt->fetchColumn()) !== false) {
            $hours[(int)(new DateTimeImmutable('@' . (int)$at))->setTimezone($this->timezone)->format('G')]++;
        }

        return $hours;
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable, int} First day, last day, number of days.
     *
     * @throws EditorException
     */
    private function range(string $from, string $to): array
    {
        $parse = fn (string $d): ?DateTimeImmutable => preg_match('/^\d{4}-\d{2}-\d{2}\z/', $d) === 1
            && ($p = DateTimeImmutable::createFromFormat('!Y-m-d', $d, $this->timezone)) !== false && $p->format('Y-m-d') === $d ? $p : null;
        $start = $parse($from);
        $end = $parse($to);
        if ($start === null || $end === null || $end < $start) {
            throw new EditorException('range_dates');
        }
        $days = (int)$start->diff($end)->days + 1;
        if ($days > self::MAX_DAYS) {
            throw new EditorException('range_long', ['max' => self::MAX_DAYS]);
        }

        return [$start, $end, $days];
    }

    /** @return array{int, int} The period as epoch seconds: the first day's midnight up to the midnight after the last day. */
    private function epochs(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return [$start->getTimestamp(), $end->add(new DateInterval('P1D'))->getTimestamp()];
    }
}
