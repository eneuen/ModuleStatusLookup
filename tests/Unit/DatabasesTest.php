<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use Modules\ModuleStatusLookup\Lib\Databases;
use PHPUnit\Framework\TestCase;

final class DatabasesTest extends TestCase
{
    public function testWalOnlyOnLocalStorage(): void
    {
        $mounts = "/dev/sda1 / ext4 rw 0 0\n//nas/pbx /storage/usbdisk1 cifs rw 0 0\n/dev/sdb1 /storage/usbdisk1/local ext4 rw 0 0\n";
        self::assertFalse(Databases::walSafe('/storage/usbdisk1/mikopbx/custom_modules/M/db/module.db', $mounts));
        self::assertTrue(Databases::walSafe('/storage/usbdisk1/local/module.db', $mounts));
        self::assertTrue(Databases::walSafe('/cf/conf/x.db', $mounts));
        self::assertTrue(Databases::walSafe('/x.db', ''));
    }
}
