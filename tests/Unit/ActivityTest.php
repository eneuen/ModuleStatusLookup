<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use DateTimeZone;
use Modules\ModuleStatusLookup\Lib\Activity;
use Modules\ModuleStatusLookup\Lib\CallLogStore;
use Modules\ModuleStatusLookup\Lib\EditorException;
use Modules\ModuleStatusLookup\Lib\Handoff;
use Modules\ModuleStatusLookup\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;

final class ActivityTest extends TestCase
{
    private PDO $db;

    private DateTimeZone $tz;

    protected function setUp(): void
    {
        $this->db = TestDatabases::module();
        $this->tz = new DateTimeZone('Europe/Berlin');
        $store = new CallLogStore($this->db);
        $n = 0;
        // [local time, outcome, last result, id, target, misses, test]
        $calls = [
            ['2026-11-10 09:15', 'hit', 'hit', '10445', '', 0, ''],
            ['2026-11-10 09:40', 'hit', 'hit', '10446', '201', 0, ''],
            ['2026-11-10 23:30', 'too_many', 'not_found', '99999', '3100', 3, ''],
            ['2026-11-11 00:10', 'operator', 'expired', '30001', '201', 1, ''],
            ['2026-11-11 09:05', 'abandoned', 'not_found', '99999', '', 1, ''],
            ['2026-11-11 10:00', 'hit', 'hit', '10445', '', 0, '1'],                 // a test call
            ['2026-11-09 12:00', 'hit', 'hit', '10445', '', 0, ''],                  // the previous period
        ];
        foreach ($calls as [$at, $outcome, $result, $id, $target, $misses, $test]) {
            $start = (new \DateTimeImmutable($at, $this->tz))->getTimestamp();
            $store->record(Handoff::fromChannel('c.' . ++$n, [
                'SL_V' => '1', 'SL_STARTED' => (string)$start, 'SL_LEFT' => (string)($start + 30), 'SL_CALLER' => '5551234',
                'SL_TRIES' => '1', 'SL_MISSES' => (string)$misses, 'SL_OUTCOME' => $outcome, 'SL_RESULT' => $result, 'SL_ID' => $id,
                'SL_STATUS' => $outcome === 'hit' ? 'READY' : '', 'SL_TARGET' => $target, 'SL_TEST' => $test,
            ], $start + 30), $this->tz);
        }
    }

    public function testSummary(): void
    {
        $s = (new Activity($this->db, $this->tz))->summary('2026-11-10', '2026-11-11');

        self::assertSame(['calls' => 5, 'hits' => 2, 'deflected' => 1, 'to_person' => 3, 'misses' => 5], $s['totals']);
        self::assertSame(['calls' => 1, 'hits' => 1, 'deflected' => 1, 'to_person' => 0, 'misses' => 0, 'from' => '2026-11-08', 'to' => '2026-11-09'],
            $s['previous']);
        self::assertSame([
            ['day' => '2026-11-10', 'calls' => 3, 'hits' => 2, 'deflected' => 1],
            ['day' => '2026-11-11', 'calls' => 2, 'hits' => 0, 'deflected' => 0],
        ], $s['trend']);
        $outcomes = array_column($s['outcomes'], 'calls', 'outcome');
        self::assertSame(['hit' => 1, 'hit_person' => 1, 'operator' => 1, 'too_many' => 1, 'abandoned' => 1], $outcomes);
        self::assertSame([['id' => '99999', 'calls' => 2], ['id' => '30001', 'calls' => 1]], $s['notFound']);
        // PBX hours, test call left out.
        self::assertSame(3, $s['hours'][9]);
        self::assertSame(1, $s['hours'][23]);
        self::assertSame(1, $s['hours'][0]);
        self::assertSame(0, $s['hours'][10]);
    }

    public function testLog(): void
    {
        $activity = new Activity($this->db, $this->tz);
        $log = $activity->log('2026-11-10', '2026-11-11', '', 1);
        self::assertSame(6, $log['total']);                 // the test call is listed, marked
        self::assertTrue($log['rows'][0]['is_test']);
        self::assertSame(30, $log['rows'][0]['seconds']);
        self::assertSame(1, $activity->log('2026-11-10', '2026-11-11', 'too_many', 1)['total']);
    }

    public function testRangesRefused(): void
    {
        $activity = new Activity($this->db, $this->tz);
        foreach ([['2026-11-11', '2026-11-10'], ['2026-02-30', '2026-03-01'], ['x', '2026-11-10'], ['2025-01-01', '2026-11-10']] as [$from, $to]) {
            try {
                $activity->summary($from, $to);
                self::fail("accepted $from..$to");
            } catch (EditorException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(EditorException::class);
        $activity->log('2026-11-10', '2026-11-11', 'won', 1);
    }
}
