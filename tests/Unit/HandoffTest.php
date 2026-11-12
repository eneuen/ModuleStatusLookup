<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use InvalidArgumentException;
use Modules\ModuleStatusLookup\Lib\Handoff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HandoffTest extends TestCase
{
    /** @return array<string, string> */
    private static function vars(array $override = []): array
    {
        return $override + [
            'SL_V' => '1', 'SL_STARTED' => '1794398400', 'SL_LEFT' => '1794398430', 'SL_CALLER' => '5551234',
            'SL_TRIES' => '2', 'SL_MISSES' => '1', 'SL_OUTCOME' => 'hit', 'SL_RESULT' => 'hit', 'SL_ID' => '10445',
            'SL_STATUS' => 'READY', 'SL_TARGET' => '', 'SL_TEST' => '',
        ];
    }

    public function testRoundTrip(): void
    {
        $record = Handoff::fromChannel('1794398400.42', self::vars(), 1794398500);

        self::assertSame('10445', $record['id_entered']);
        self::assertSame('1794398430', $record['ended_at']);
        self::assertSame($record, Handoff::decode(Handoff::encode($record)));
    }

    public function testARetriedRecordAndAnOlderOneStillDecode(): void
    {
        $record = Handoff::fromChannel('1794398400.42', self::vars(), 1794398500);
        // The worker puts a record it couldn't store back on the queue with a retry count.
        self::assertSame($record, Handoff::decode(json_encode($record + ['retries' => 3], JSON_THROW_ON_ERROR)));
        // A record queued by the previous version has no PIN fields.
        $old = $record;
        unset($old['pin_fails'], $old['pin_id']);
        self::assertSame(['pin_fails' => '', 'pin_id' => ''], array_intersect_key(Handoff::decode(json_encode($old, JSON_THROW_ON_ERROR)), ['pin_fails' => 1, 'pin_id' => 1]));
    }

    public function testHangUpBeforeLeavingUsesNow(): void
    {
        $record = Handoff::fromChannel('1.1', self::vars(['SL_LEFT' => '', 'SL_OUTCOME' => 'abandoned', 'SL_RESULT' => '']), 1794398500);

        self::assertSame('1794398500', $record['ended_at']);
    }

    public function testCallerReducedToDigits(): void
    {
        self::assertSame('123', Handoff::fromChannel('1.1', self::vars(['SL_CALLER' => '123;Hangup()']), 1)['caller']);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function bad(): iterable
    {
        yield 'unknown outcome' => [['SL_OUTCOME' => 'won']];
        yield 'id with letters' => [['SL_ID' => '10a45']];
        yield 'status code injection' => [['SL_STATUS' => 'READY<script>']];
        yield 'target with dialplan' => [['SL_TARGET' => '2000,1']];
        yield 'missing start' => [['SL_STARTED' => '']];
        yield 'old version' => [['SL_V' => '0']];
    }

    #[DataProvider('bad')]
    public function testRejected(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);
        Handoff::fromChannel('1.1', self::vars($override), 1);
    }

    public function testDecodeRejectsGarbage(): void
    {
        foreach (['not json', '"text"', '{"linkedid":"x;y"}', json_encode(['linkedid' => '1.1'])] as $message) {
            try {
                Handoff::decode((string)$message);
                self::fail("accepted: $message");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
