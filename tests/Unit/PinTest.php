<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use Modules\ModuleStatusLookup\Lib\Pin;
use PHPUnit\Framework\TestCase;

final class PinTest extends TestCase
{
    public function testHashAndMatch(): void
    {
        $pin = new Pin(str_repeat('k', 64));
        $hash = $pin->hash('4821');

        self::assertStringStartsWith('h1:', $hash);
        self::assertStringNotContainsString('4821', $hash);
        self::assertTrue($pin->matches('4821', $hash));
        self::assertFalse($pin->matches('4822', $hash));
        self::assertFalse($pin->matches('4821', ''));
        self::assertFalse($pin->matches('', $hash));
        // Another key gives another hash: a copied database is useless without the key file.
        self::assertNotSame($hash, (new Pin(str_repeat('j', 64)))->hash('4821'));
    }

    public function testValid(): void
    {
        foreach (['1234' => true, '12345678' => true, '123' => false, '123456789' => false, '12a4' => false, "1234\n" => false] as $p => $ok) {
            self::assertSame($ok, Pin::isValid((string)$p), (string)$p);
        }
    }

    public function testKeyFileIsCreatedOnceAndPrivate(): void
    {
        $file = sys_get_temp_dir() . '/sl-pin-' . bin2hex(random_bytes(4)) . '.key';
        try {
            $a = Pin::fromFile($file);
            $b = Pin::fromFile($file);
            self::assertSame($a->hash('1234'), $b->hash('1234'));
            self::assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
            // The core resets module files to 0644 on install; the next read makes the key private again.
            chmod($file, 0644);
            Pin::fromFile($file);
            clearstatcache();
            self::assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
            self::assertSame([], glob(dirname($file) . '/.pin-*') ?: [], 'no temporary file is left behind');
        } finally {
            @unlink($file);
        }
    }

    public function testALinkOrADamagedKeyIsRefused(): void
    {
        $dir = sys_get_temp_dir() . '/sl-pin-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $target = "$dir/elsewhere";
        file_put_contents($target, str_repeat('a', 64));
        try {
            symlink($target, "$dir/pin.key");
            try {
                Pin::fromFile("$dir/pin.key");
                self::fail('a link was used as the key');
            } catch (\RuntimeException) {
                self::assertTrue(is_link("$dir/pin.key"));
            }
            unlink("$dir/pin.key");
            file_put_contents("$dir/pin.key", '');
            $this->expectException(\RuntimeException::class);
            Pin::fromFile("$dir/pin.key");
        } finally {
            @unlink("$dir/pin.key");
            @unlink($target);
            @rmdir($dir);
        }
    }
}
