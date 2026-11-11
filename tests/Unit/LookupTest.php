<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use DateTimeZone;
use Modules\ModuleStatusLookup\Lib\Lookup;
use Modules\ModuleStatusLookup\Lib\Settings;
use Modules\ModuleStatusLookup\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LookupTest extends TestCase
{
    /** 2026-11-11 12:00 UTC. */
    private const int NOW = 1794398400;

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = TestDatabases::module();
        $status = $this->db->prepare('INSERT INTO m_StatusLookupStatuses (code, name, prompt_id, spoken_extra, after_action, transfer_number)
            VALUES (?, ?, ?, ?, ?, ?)');
        $status->execute(['READY', 'Ready', 11, 'id', 'repeat', '']);
        $status->execute(['SHIPPED', 'Shipped', 12, 'date', 'hangup', '']);
        $status->execute(['NEEDS_APPROVAL', 'Needs approval', 13, 'none', 'transfer', '2001']);
        $status->execute(['NO_PROMPT', 'Gone prompt', 99, 'none', 'repeat', '']);
        $record = $this->db->prepare('INSERT INTO m_StatusLookupRecords (lookup_id, status_code, status_date, expires_on) VALUES (?, ?, ?, ?)');
        $record->execute(['10445', 'READY', '', '']);
        $record->execute(['00451', 'SHIPPED', '2026-11-12', '']);
        $record->execute(['451', 'READY', '', '2026-11-10']);
        $record->execute(['20001', 'NEEDS_APPROVAL', '', '2026-11-11']);
        $record->execute(['30001', 'NO_PROMPT', '', '']);
        $record->execute(['30002', 'UNKNOWN', '', '']);
        $record->execute(['30003', 'SHIPPED', 'not a date', '']);
    }

    private function lookup(array $settings = []): Lookup
    {
        $prompts = [11 => '/storage/media/ready', 12 => '/storage/media/shipped', 13 => '/storage/media/approval'];

        return new Lookup($this->db, $settings + Settings::DEFAULTS, new DateTimeZone('Europe/Berlin'),
            static fn (int $id): ?string => $prompts[$id] ?? null);
    }

    public function testHitWithRepeatMenuAndSpokenId(): void
    {
        $vars = $this->lookup()->find('10445', '5551234', 0, self::NOW);

        self::assertSame('hit', $vars['SL_RESULT']);
        self::assertSame('10445', $vars['SL_ID']);
        self::assertSame('READY', $vars['SL_STATUS']);
        self::assertSame('/storage/media/ready', $vars['SL_PROMPT']);
        self::assertSame('id', $vars['SL_EXTRA']);
        self::assertSame('repeat', $vars['SL_ACTION']);
        self::assertSame('', $vars['SL_TRANSFER']);
    }

    public function testLeadingZerosAreADifferentRecord(): void
    {
        self::assertSame('SHIPPED', $this->lookup()->find('00451', '', 0, self::NOW)['SL_STATUS']);
        self::assertSame('expired', $this->lookup(['id_min_length' => 3])->find('451', '', 0, self::NOW)['SL_RESULT']);
    }

    public function testSpokenDateIsNoonInThePbxTimeZone(): void
    {
        $vars = $this->lookup()->find('00451', '', 0, self::NOW);

        self::assertSame('date', $vars['SL_EXTRA']);
        // 2026-11-12 12:00 in Berlin (UTC+1).
        self::assertSame((string)(1794398400 + 86400 - 3600), $vars['SL_DATE']);
    }

    public function testBadDateFallsBackToNoExtra(): void
    {
        $vars = $this->lookup()->find('30003', '', 0, self::NOW);

        self::assertSame('hit', $vars['SL_RESULT']);
        self::assertSame('none', $vars['SL_EXTRA']);
        self::assertSame('', $vars['SL_DATE']);
    }

    public function testExpiresAtTheEndOfItsLastDay(): void
    {
        // Last day is today: still read out.
        self::assertSame('hit', $this->lookup()->find('20001', '', 0, self::NOW)['SL_RESULT']);
        self::assertSame('expired', $this->lookup()->find('20001', '', 0, self::NOW + 86400)['SL_RESULT']);
    }

    public function testTransferStatus(): void
    {
        $vars = $this->lookup()->find('20001', '', 0, self::NOW);

        self::assertSame('transfer', $vars['SL_ACTION']);
        self::assertSame('2001', $vars['SL_TRANSFER']);
    }

    public function testNotFound(): void
    {
        $vars = $this->lookup()->find('99999', '', 0, self::NOW);

        self::assertSame('not_found', $vars['SL_RESULT']);
        self::assertSame('99999', $vars['SL_ID']);
        self::assertSame('', $vars['SL_PROMPT']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInputs(): iterable
    {
        yield 'nothing' => [''];
        yield 'too short' => ['123'];
        yield 'too long' => ['12345678901'];
        yield 'star' => ['10*45'];
        yield 'hash' => ['#'];
        yield 'sql' => ["1' OR '1'='1"];
        yield 'newline' => ["10445\n"];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputNeverReachesTheDatabase(string $input): void
    {
        $vars = $this->lookup()->find($input, '', 0, self::NOW);

        self::assertSame('invalid', $vars['SL_RESULT']);
        self::assertSame('', $vars['SL_ID']);
    }

    public function testMinimumLengthIsAtLeastTwo(): void
    {
        self::assertSame('invalid', $this->lookup(['id_min_length' => 1])->find('7', '', 0, self::NOW)['SL_RESULT']);
    }

    public function testUnusableStatusIsAFault(): void
    {
        self::assertSame('fault', $this->lookup()->find('30001', '', 0, self::NOW)['SL_RESULT']);
        self::assertSame('fault', $this->lookup()->find('30002', '', 0, self::NOW)['SL_RESULT']);
    }

    public function testCallerMissLimitCountsThisCallAndTheLastHour(): void
    {
        $this->logMisses('5551234', 8, self::NOW - 600);
        $this->logMisses('5551234', 5, self::NOW - 7200);   // more than an hour ago
        $this->logMisses('5559999', 9, self::NOW - 600);    // someone else

        self::assertSame('hit', $this->lookup()->find('10445', '5551234', 1, self::NOW)['SL_RESULT']);
        self::assertSame('rate_limited', $this->lookup()->find('10445', '5551234', 2, self::NOW)['SL_RESULT']);
    }

    public function testWithheldCallersShareOneBucket(): void
    {
        $this->logMisses('', 10, self::NOW - 60);

        self::assertSame('rate_limited', $this->lookup()->find('10445', '', 0, self::NOW)['SL_RESULT']);
    }

    public function testPbxWideMissLimit(): void
    {
        foreach (range(1, 20) as $n) {
            $this->logMisses("55500$n", 5, self::NOW - 60);
        }

        self::assertSame('hit', $this->lookup()->find('10445', '5551234', 0, self::NOW)['SL_RESULT']);
        self::assertSame('rate_limited', $this->lookup(['miss_limit_total' => 100])->find('10445', '5551234', 0, self::NOW)['SL_RESULT']);
    }

    public function testTestCallsDontCountAndZeroTurnsTheLimitOff(): void
    {
        $this->logMisses('5551234', 50, self::NOW - 60, true);
        self::assertSame('hit', $this->lookup()->find('10445', '5551234', 0, self::NOW)['SL_RESULT']);

        $this->logMisses('5551234', 50, self::NOW - 60);
        self::assertSame('hit', $this->lookup(['miss_limit_caller' => 0, 'miss_limit_total' => 0])
            ->find('10445', '5551234', 0, self::NOW)['SL_RESULT']);
    }

    public function testDigits(): void
    {
        self::assertSame('4930123', Lookup::digits('+49 (30) 123'));
        self::assertSame('123', Lookup::digits('123;Hangup()'));
        self::assertSame(32, strlen(Lookup::digits(str_repeat('9', 40))));
    }

    private function logMisses(string $caller, int $misses, int $at, bool $test = false): void
    {
        $this->db->prepare("INSERT INTO m_StatusLookupCallLog (linkedid, started_at, ended_at, caller, misses, outcome, is_test)
            VALUES ('x', ?, ?, ?, ?, 'too_many', ?)")->execute([$at, $at, $caller, $misses, $test ? '1' : '0']);
    }
}
