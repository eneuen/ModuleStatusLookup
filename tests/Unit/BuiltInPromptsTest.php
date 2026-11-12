<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use Modules\ModuleStatusLookup\Lib\BuiltInPrompts;
use Modules\ModuleStatusLookup\Lib\Settings;
use PHPUnit\Framework\TestCase;

final class BuiltInPromptsTest extends TestCase
{
    public function testPlayback(): void
    {
        self::assertSame('modulestatuslookup-enter-id', BuiltInPrompts::playback(-2, 'ru-ru', '/snd'));
        self::assertSame('/snd/en-en/modulestatuslookup-enter-id', BuiltInPrompts::playback(-2, 'de-de', '/snd/'));
        self::assertNull(BuiltInPrompts::playback(5, 'en-en', '/snd'));
        self::assertNull(BuiltInPrompts::playback(-99, 'en-en', '/snd'));
    }

    public function testEveryPromptSettingDefaultsToItsBuiltIn(): void
    {
        foreach (BuiltInPrompts::IDS as $key => $id) {
            self::assertSame($id, Settings::DEFAULTS[str_replace('-', '_', $key) . '_prompt_id'], $key);
        }
    }

    public function testRecordingsShipForEveryPromptAndLanguage(): void
    {
        $dir = dirname(__DIR__, 2) . '/Sounds';
        if (!is_dir($dir)) {
            self::markTestSkipped('The recordings are not in the repository yet.');
        }
        foreach (BuiltInPrompts::LANGUAGES as $language) {
            foreach (array_keys(BuiltInPrompts::IDS) as $key) {
                self::assertFileExists("$dir/$language/$key.wav");
            }
        }
    }

    public function testEveryPromptIsRecordedInEveryLanguageInTheTelephoneFormat(): void
    {
        $sounds = __DIR__ . '/../../' . BuiltInPrompts::SOUNDS_DIR;
        self::assertFileExists("$sounds/LICENSE");
        foreach (BuiltInPrompts::LANGUAGES as $language) {
            foreach (array_keys(BuiltInPrompts::IDS) as $key) {
                $file = "$sounds/$language/$key.wav";
                self::assertFileExists($file);
                // RIFF/WAVE, PCM (1), mono, 8000 Hz, 16 bits: what the PBX converts from without resampling.
                $header = unpack('a4riff/Vsize/a4wave/a4fmt/Vfmtsize/vformat/vchannels/Vrate/Vbyterate/vblock/vbits', (string)file_get_contents($file, false, null, 0, 36));
                self::assertSame(['RIFF', 'WAVE', 1, 1, 8000, 16], [$header['riff'], $header['wave'], $header['format'], $header['channels'], $header['rate'], $header['bits']], $file);
            }
        }
    }
}
