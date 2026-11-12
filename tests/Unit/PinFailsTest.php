<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use Modules\ModuleStatusLookup\Lib\CallLogStore;
use Modules\ModuleStatusLookup\Lib\Databases;
use Modules\ModuleStatusLookup\Lib\Handoff;
use Modules\ModuleStatusLookup\Lib\PinFails;
use Modules\ModuleStatusLookup\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;

final class PinFailsTest extends TestCase
{
    public function testCountedPerId(): void
    {
        // 8 wrong PINs for one ID, then one for another: each keeps its own count.
        $list = '';
        for ($i = 0; $i < 8; $i++) {
            $list = PinFails::add($list, '40001');
        }
        $list = PinFails::add($list, '40002');
        self::assertSame('40001:8;40002:1;', $list);
        self::assertSame(['40001' => 8, '40002' => 1], PinFails::parse($list));
    }

    public function testMalformedListsAreEmpty(): void
    {
        foreach (['40001', '40001:x;', 'a:1;', '40001:100;', str_repeat('1:1;', 10)] as $bad) {
            self::assertSame([], PinFails::parse($bad), $bad);
        }
    }

    public function testStoredPerIdWithTheCall(): void
    {
        $db = TestDatabases::module();
        (new CallLogStore($db))->record(Handoff::fromChannel('c.1', [
            'SL_V' => '1', 'SL_STARTED' => '1794398400', 'SL_LEFT' => '1794398460', 'SL_CALLER' => '5551234', 'SL_TRIES' => '3',
            'SL_MISSES' => '3', 'SL_OUTCOME' => 'too_many', 'SL_RESULT' => 'pin_failed', 'SL_ID' => '40002', 'SL_STATUS' => '',
            'SL_TARGET' => '', 'SL_TEST' => '', 'SL_PINFAILS' => '40001:2;40002:1;',
        ], 1794398460), new \DateTimeZone('UTC'));
        self::assertSame([['pin_id' => '40001', 'fails' => 2], ['pin_id' => '40002', 'fails' => 1]],
            $db->query('SELECT pin_id, fails FROM m_StatusLookupPinFails ORDER BY pin_id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testWalOnlyOnLocalStorage(): void
    {
        $mounts = "/dev/sda1 / ext4 rw 0 0\n//nas/pbx /storage/usbdisk1 cifs rw 0 0\n/dev/sdb1 /storage/usbdisk1/local ext4 rw 0 0\n";
        self::assertFalse(Databases::walSafe('/storage/usbdisk1/mikopbx/custom_modules/M/db/module.db', $mounts));
        self::assertTrue(Databases::walSafe('/storage/usbdisk1/local/module.db', $mounts));
        self::assertTrue(Databases::walSafe('/cf/conf/x.db', $mounts));
        self::assertTrue(Databases::walSafe('/x.db', ''));
    }
}
