<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\ModuleStatusLookup\Lib\CallLogStore;
use Modules\ModuleStatusLookup\Lib\EditorException;
use Modules\ModuleStatusLookup\Lib\Handoff;
use Modules\ModuleStatusLookup\Lib\Retention;
use Modules\ModuleStatusLookup\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;

final class RetentionTest extends TestCase
{
    private PDO $db;

    private DateTimeZone $tz;

    protected function setUp(): void
    {
        $this->db = TestDatabases::module();
        $this->tz = new DateTimeZone('Europe/Berlin');
        $store = new CallLogStore($this->db);
        $n = 0;
        // [local time, caller, ID typed]
        foreach ([
            ['2026-11-01 23:59', '4915112345678', '10445'],
            ['2026-11-02 00:00', '015112345678', '10446'],
            ['2026-11-11 09:00', '4930999888', '10445'],
            ['2026-11-12 08:00', '', '20001'],
        ] as [$at, $caller, $id]) {
            $start = (new DateTimeImmutable($at, $this->tz))->getTimestamp();
            $store->record(Handoff::fromChannel('c.' . ++$n, [
                'SL_V' => '1', 'SL_STARTED' => (string)$start, 'SL_LEFT' => (string)($start + 20), 'SL_CALLER' => $caller,
                'SL_TRIES' => '1', 'SL_MISSES' => '0', 'SL_OUTCOME' => 'hit', 'SL_RESULT' => 'hit', 'SL_ID' => $id,
                'SL_STATUS' => 'READY', 'SL_TARGET' => '', 'SL_TEST' => '',
            ], $start + 20), $this->tz);
        }
        $insert = $this->db->prepare("INSERT INTO m_StatusLookupRecords (lookup_id, status_code, expires_on, phone, updated_at, source)
            VALUES (?, 'READY', ?, ?, 0, 'form')");
        foreach ([['10445', '', '+4915112345678'], ['10446', '2026-11-11', ''], ['10447', '2026-11-12', '4930999888'], ['10448', 'soon', '']] as $r) {
            $insert->execute($r);
        }
    }

    public function testLogDaysFollowThePbx(): void
    {
        self::assertSame(0, Retention::logDays(0, 0));
        self::assertSame(0, Retention::logDays(0, 29));
        self::assertSame(30, Retention::logDays(0, 30));
        self::assertSame(7, Retention::logDays(7, 365));
        self::assertSame(Retention::MAX_DAYS, Retention::logDays(99999, 0));
    }

    public function testNightlyRunKeepsWholeDays(): void
    {
        $now = (new DateTimeImmutable('2026-11-12 03:00', $this->tz))->getTimestamp();
        $retention = new Retention($this->db, $this->tz);
        // 11 days: 2 November (00:00) to today stay; the call a minute before midnight goes.
        self::assertSame(['calls' => 1, 'records' => 0], $retention->run($now, 11, false));
        self::assertSame(['10446', '10445', '20001'], $this->ids());
        // Kept for ever.
        self::assertSame(['calls' => 0, 'records' => 0], $retention->run($now, 0, false));
        // The daily counts stay.
        self::assertSame(4, (int)$this->db->query('SELECT SUM(calls) FROM m_StatusLookupDailyCounts')->fetchColumn());
    }

    public function testPurgeExpiredKeepsTheLastDayAndMalformedDates(): void
    {
        $now = (new DateTimeImmutable('2026-11-12 03:00', $this->tz))->getTimestamp();
        self::assertSame(['calls' => 0, 'records' => 1], (new Retention($this->db, $this->tz))->run($now, 0, true));
        self::assertSame(['10445', '10447', '10448'], $this->db->query('SELECT lookup_id FROM m_StatusLookupRecords ORDER BY lookup_id')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testForgetId(): void
    {
        $retention = new Retention($this->db, $this->tz);
        self::assertSame(['records' => 1, 'calls' => 2], $retention->forgetId('TK-10445'));
        self::assertSame(['10446', '20001'], $this->ids());
        self::assertSame(['records' => 0, 'calls' => 0], $retention->forgetId('10445'));
        $this->expectException(EditorException::class);
        $retention->forgetId('');
    }

    public function testForgetCallerMatchesAsTheLookupDoes(): void
    {
        $retention = new Retention($this->db, $this->tz);
        self::assertSame(['calls' => 2, 'records' => 1], $retention->forgetCaller('+49 151 12345678'));
        self::assertSame(['10445', '20001'], $this->ids());
        self::assertSame('', $this->db->query("SELECT phone FROM m_StatusLookupRecords WHERE lookup_id = '10445'")->fetchColumn());
        self::assertSame('4930999888', $this->db->query("SELECT phone FROM m_StatusLookupRecords WHERE lookup_id = '10447'")->fetchColumn());
    }

    public function testForgetCallerNeedsEnoughDigits(): void
    {
        $this->expectException(EditorException::class);
        (new Retention($this->db, $this->tz))->forgetCaller('12345');
    }

    /** @return list<string> IDs typed in the calls still logged, oldest first. */
    private function ids(): array
    {
        return $this->db->query('SELECT id_entered FROM m_StatusLookupCallLog ORDER BY started_at')->fetchAll(PDO::FETCH_COLUMN);
    }
}
