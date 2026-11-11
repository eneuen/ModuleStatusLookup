<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use DateTimeZone;
use Modules\ModuleStatusLookup\Lib\CallLogStore;
use Modules\ModuleStatusLookup\Lib\Handoff;
use Modules\ModuleStatusLookup\Tests\Unit\Support\TestDatabases;
use PHPUnit\Framework\TestCase;

final class CallLogStoreTest extends TestCase
{
    private static function record(array $override = [], string $linkedId = '1794398400.1'): array
    {
        return Handoff::fromChannel($linkedId, $override + [
            'SL_V' => '1', 'SL_STARTED' => '1794398400', 'SL_LEFT' => '1794398430', 'SL_CALLER' => '5551234',
            'SL_TRIES' => '2', 'SL_MISSES' => '1', 'SL_OUTCOME' => 'hit', 'SL_RESULT' => 'hit', 'SL_ID' => '10445',
            'SL_STATUS' => 'READY', 'SL_TARGET' => '', 'SL_TEST' => '',
        ], 1794398500);
    }

    public function testLogAndDailyCounts(): void
    {
        $db = TestDatabases::module();
        $store = new CallLogStore($db);
        $tz = new DateTimeZone('Europe/Berlin');
        $store->record(self::record([], 'a.1'), $tz);
        $store->record(self::record(['SL_MISSES' => '2'], 'a.2'), $tz);
        $store->record(self::record(['SL_OUTCOME' => 'operator', 'SL_TARGET' => '2000'], 'a.3'), $tz);
        $store->record(self::record(['SL_TEST' => '1'], 'a.4'), $tz);

        self::assertSame(4, (int)$db->query('SELECT COUNT(*) FROM m_StatusLookupCallLog')->fetchColumn());
        self::assertSame(1, (int)$db->query("SELECT COUNT(*) FROM m_StatusLookupCallLog WHERE is_test = '1'")->fetchColumn());
        $counts = $db->query('SELECT day, outcome, to_person, calls, misses FROM m_StatusLookupDailyCounts ORDER BY outcome')->fetchAll();
        self::assertSame([
            ['day' => '2026-11-11', 'outcome' => 'hit', 'to_person' => '0', 'calls' => 2, 'misses' => 3],
            ['day' => '2026-11-11', 'outcome' => 'operator', 'to_person' => '1', 'calls' => 1, 'misses' => 1],
        ], array_map(static fn (array $r): array => ['day' => $r['day'], 'outcome' => $r['outcome'], 'to_person' => (string)$r['to_person'],
            'calls' => (int)$r['calls'], 'misses' => (int)$r['misses']], $counts));
    }

    public function testTheSameVisitHandedOffTwiceIsStoredOnce(): void
    {
        $db = TestDatabases::module();
        $store = new CallLogStore($db);
        $tz = new DateTimeZone('UTC');

        self::assertTrue($store->record(self::record(), $tz));
        self::assertFalse($store->record(self::record(), $tz));
        // A later visit in the same call (back to the line from an IVR menu) is its own row.
        self::assertTrue($store->record(self::record(['SL_STARTED' => '1794398500', 'SL_LEFT' => '1794398520']), $tz));
        self::assertSame(2, (int)$db->query('SELECT COUNT(*) FROM m_StatusLookupCallLog')->fetchColumn());
        self::assertSame(2, (int)$db->query('SELECT SUM(calls) FROM m_StatusLookupDailyCounts')->fetchColumn());
    }
}
