<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every text the page uses exists in English and Russian, with every plural
 * form each language needs; a missing one would show the English fallback
 * (or the key) to a Russian admin.
 */
final class MessagesTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    public function testEveryPageTextIsTranslated(): void
    {
        $script = (string)file_get_contents(self::ROOT . '/public/assets/js/src/status-lookup.js');
        $view = (string)file_get_contents(self::ROOT . '/App/Views/ModuleStatusLookup/index.volt');
        preg_match_all("/(?:\\bt\\(|\\[)\\s*'(sl_[A-Za-z0-9_\\-]+)',\\s*'/", $script, $plain);
        preg_match_all("/plural\\('(sl_[A-Za-z0-9_]+)'/", $script, $plurals);
        preg_match_all("/t\\._\\('(sl_[A-Za-z0-9_]+)'\\)/", $view, $volt);
        $en = require self::ROOT . '/Messages/en.php';
        $ru = require self::ROOT . '/Messages/ru.php';

        $needed = ['en' => [], 'ru' => []];
        foreach (array_merge($plain[1], $volt[1]) as $key) {
            $needed['en'][] = $key;
            $needed['ru'][] = $key;
        }
        foreach ($plurals[1] as $key) {
            array_push($needed['en'], "{$key}_one", "{$key}_other");
            array_push($needed['ru'], "{$key}_one", "{$key}_few", "{$key}_many", "{$key}_other");
        }
        self::assertSame([], array_values(array_diff(array_unique($needed['en']), array_keys($en))), 'missing in en.php');
        self::assertSame([], array_values(array_diff(array_unique($needed['ru']), array_keys($ru))), 'missing in ru.php');
        self::assertSame([], array_values(array_diff(array_keys($en), array_keys($ru))), 'in en.php but not ru.php');
    }

    public function testPlaceholdersMatch(): void
    {
        $en = require self::ROOT . '/Messages/en.php';
        $ru = require self::ROOT . '/Messages/ru.php';
        foreach ($en as $key => $text) {
            preg_match_all('/\{[a-z]+\}/', $text, $a);
            preg_match_all('/\{[a-z]+\}/', (string)($ru[$key] ?? ''), $b);
            $a = array_unique($a[0]);
            $b = array_unique($b[0]);
            sort($a);
            sort($b);
            // Russian plural forms may leave {n} out (as in "Добавлено статусов: {n}" vs one form); the rest must match.
            self::assertSame(array_values(array_diff($a, ['{n}'])), array_values(array_diff($b, ['{n}'])), $key);
        }
    }
}
