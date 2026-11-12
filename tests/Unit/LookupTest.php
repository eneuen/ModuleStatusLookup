<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use DateTimeZone;
use Modules\ModuleStatusLookup\Lib\Lookup;
use Modules\ModuleStatusLookup\Lib\Pin;
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
        $this->db->exec("INSERT INTO m_StatusLookupStatuses (code, name, prompt_id, spoken_extra, after_action, requires_pin, requires_callerid)
            VALUES ('PINNED', 'Pinned', 11, 'none', 'repeat', '1', '0'), ('MINE', 'Mine', 11, 'none', 'repeat', '0', '1')");
        $record = $this->db->prepare('INSERT INTO m_StatusLookupRecords (lookup_id, status_code, status_date, expires_on) VALUES (?, ?, ?, ?)');
        $record->execute(['10445', 'READY', '', '']);
        $record->execute(['00451', 'SHIPPED', '2026-11-12', '']);
        $record->execute(['451', 'READY', '', '2026-11-10']);
        $record->execute(['20001', 'NEEDS_APPROVAL', '', '2026-11-11']);
        $record->execute(['30001', 'NO_PROMPT', '', '']);
        $record->execute(['30002', 'UNKNOWN', '', '']);
        $record->execute(['30003', 'SHIPPED', 'not a date', '']);
        $this->db->prepare("INSERT INTO m_StatusLookupRecords (lookup_id, status_code, pin_hash, phone) VALUES (?, ?, ?, ?)")
            ->execute(['40001', 'PINNED', (new Pin(str_repeat('k', 64)))->hash('4821'), '']);
        $this->db->exec("INSERT INTO m_StatusLookupRecords (lookup_id, status_code, pin_hash, phone) VALUES
            ('40002', 'PINNED', '', ''), ('40003', 'MINE', '', '+49 30 1234567'), ('40004', 'MINE', '', '')");
        $record->execute(['30004', 'READY', '', '2099-1-1']);
    }

    private function lookup(array $settings = []): Lookup
    {
        $prompts = [11 => '/storage/media/ready', 12 => '/storage/media/shipped', 13 => '/storage/media/approval'];

        return new Lookup($this->db, $settings + Settings::DEFAULTS, new DateTimeZone('Europe/Berlin'),
            static fn (int $id): ?string => $prompts[$id] ?? null, new Pin(str_repeat('k', 64)));
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

    public function testAMalformedLastDayCountsAsPast(): void
    {
        self::assertSame('expired', $this->lookup()->find('30004', '', 0, self::NOW)['SL_RESULT']);
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

    public function testIdLengthsAreClampedToWhatTheCallLogAccepts(): void
    {
        self::assertSame([2, 20], Lookup::idLengths(['id_min_length' => 0, 'id_max_length' => 99]));
        self::assertSame([20, 20], Lookup::idLengths(['id_min_length' => 30, 'id_max_length' => 5]));
        self::assertSame([6, 6], Lookup::idLengths(['id_min_length' => 6, 'id_max_length' => 3]));
        self::assertSame('invalid', $this->lookup(['id_max_length' => 30])->find(str_repeat('1', 21), '', 0, self::NOW)['SL_RESULT']);
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

    public function testPinIsAskedForAndNothingIsSaidBeforeItMatches(): void
    {
        $asked = $this->lookup()->find('40001', '', 0, self::NOW);
        self::assertSame('need_pin', $asked['SL_RESULT']);
        self::assertSame(['', ''], [$asked['SL_STATUS'], $asked['SL_PROMPT']]);

        self::assertSame('pin_failed', $this->lookup()->find('40001', '', 0, self::NOW, '0000')['SL_RESULT']);
        self::assertSame('pin_failed', $this->lookup()->find('40001', '', 0, self::NOW, '')['SL_RESULT']);
        $hit = $this->lookup()->find('40001', '', 0, self::NOW, '4821');
        self::assertSame(['hit', 'PINNED'], [$hit['SL_RESULT'], $hit['SL_STATUS']]);
    }

    public function testStatusAskingForAPinWithoutOneStoredPutsTheCallerThrough(): void
    {
        self::assertSame('no_pin', $this->lookup()->find('40002', '', 0, self::NOW)['SL_RESULT']);
        // Without a key, no PIN can be checked: the same.
        $noKey = new Lookup($this->db, Settings::DEFAULTS, new DateTimeZone('UTC'), static fn (int $id): ?string => '/x/y');
        self::assertSame('no_pin', $noKey->find('40001', '', 0, self::NOW, '4821')['SL_RESULT']);
    }

    public function testCallerNumberMustBeTheRecordsPhone(): void
    {
        self::assertSame('hit', $this->lookup()->find('40003', '0301234567', 0, self::NOW)['SL_RESULT']);
        self::assertSame('hit', $this->lookup()->find('40003', '49301234567', 0, self::NOW)['SL_RESULT']);
        self::assertSame('callerid_failed', $this->lookup()->find('40003', '0301234568', 0, self::NOW)['SL_RESULT']);
        self::assertSame('callerid_failed', $this->lookup()->find('40003', '', 0, self::NOW)['SL_RESULT']);
    }

    public function testTheMissCountedForBeingAskedDoesntRefuseTheRightPin(): void
    {
        // A limit of 1: being asked for the PIN counted this call's one miss; the right PIN is still heard.
        $lookup = $this->lookup(['miss_limit_caller' => 1]);
        self::assertSame('need_pin', $lookup->find('40001', '5551234', 0, self::NOW)['SL_RESULT']);
        self::assertSame('hit', $lookup->find('40001', '5551234', 1, self::NOW, '4821')['SL_RESULT']);
        // A real miss earlier in the call still counts.
        self::assertSame('rate_limited', $lookup->find('40001', '5551234', 2, self::NOW, '4821')['SL_RESULT']);
    }

    public function testRecordWithoutAPhoneIsntToldButPutThrough(): void
    {
        self::assertSame('no_phone', $this->lookup()->find('40004', '0301234567', 0, self::NOW)['SL_RESULT']);
    }

    public function testTooManyWrongPinsForOneIdFromAnyCaller(): void
    {
        $log = $this->db->prepare('INSERT INTO m_StatusLookupPinFails (started_at, fails, pin_id) VALUES (?, ?, ?)');
        $log->execute([self::NOW - 90000, 9, '40001']);     // more than a day ago: doesn't count
        $log->execute([self::NOW - 3000, 3, '40001']);
        $log->execute([self::NOW - 2000, 3, '40001']);
        $log->execute([self::NOW - 1000, 3, '99999']);      // another ID
        self::assertSame('need_pin', $this->lookup()->find('40001', '555', 0, self::NOW)['SL_RESULT']);
        // With this call's own wrong PINs: 6 + 3 = 9, then 10.
        self::assertSame('pin_failed', $this->lookup()->find('40001', '555', 0, self::NOW, '0000', 3)['SL_RESULT']);
        self::assertSame('pin_locked', $this->lookup()->find('40001', '555', 0, self::NOW, '4821', 4)['SL_RESULT']);
        self::assertSame('pin_locked', $this->lookup()->find('40001', '666', 0, self::NOW, null, 4)['SL_RESULT']);
    }

    public function testSamePhone(): void
    {
        self::assertTrue(Lookup::samePhone('+49 30 1234567', '030 1234567'));
        self::assertFalse(Lookup::samePhone('123456', '123456'));       // too short to tell
        self::assertFalse(Lookup::samePhone('5551234', ''));
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
