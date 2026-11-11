<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use Modules\ModuleStatusLookup\Lib\IvrNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IvrNumberTest extends TestCase
{
    public function testDefaultWhenFree(): void
    {
        self::assertSame('2300', IvrNumber::firstFree(['201', '2200', '2200100']));
    }

    public function testNextFreeInRange(): void
    {
        self::assertSame('2302', IvrNumber::firstFree(['2300', '2301', '2303']));
    }

    public function testNullWhenRangeFull(): void
    {
        self::assertNull(IvrNumber::firstFree(array_map('strval', range(2300, 2399))));
    }

    public function testNewInstallTakesBackItsLeftoverNumber(): void
    {
        self::assertSame('2300', IvrNumber::forNewInstall(['201', '2300'], ['2300']));
        self::assertSame('2305', IvrNumber::forNewInstall(['2300', '2305'], ['2305', 'x']));
        self::assertSame('2301', IvrNumber::forNewInstall(['2300'], []));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function numbers(): iterable
    {
        yield 'default' => ['2300', true];
        yield 'two digits' => ['22', true];
        yield 'eight digits' => ['23000000', true];
        yield 'one digit' => ['2', false];
        yield 'nine digits' => ['230000000', false];
        yield 'leading zero' => ['0230', false];
        yield 'empty' => ['', false];
        yield 'letters' => ['23a0', false];
        yield 'dialplan characters' => ['2300,1,Hangup()', false];
        yield 'newline' => ["2300\n", false];
    }

    #[DataProvider('numbers')]
    public function testIsValid(string $number, bool $valid): void
    {
        self::assertSame($valid, IvrNumber::isValid($number));
    }

    public function testCallerIdCarriesTheNumber(): void
    {
        self::assertSame('Status Lookup (2300)', IvrNumber::callerId('Status Lookup', '2300'));
    }
}
