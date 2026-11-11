<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use InvalidArgumentException;
use Modules\ModuleStatusLookup\Lib\Settings;
use Modules\ModuleStatusLookup\Tests\Unit\Support\TestDatabases;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testDefaultsWithoutARow(): void
    {
        self::assertSame(Settings::DEFAULTS, Settings::load(TestDatabases::module()));
    }

    public function testEnsureRowWritesDefaultsOnce(): void
    {
        $db = TestDatabases::module();
        Settings::ensureRow($db);
        Settings::save($db, ['internal_number' => '2301']);
        Settings::ensureRow($db);

        self::assertSame('2301', Settings::load($db)['internal_number']);
        self::assertSame(1, (int)$db->query('SELECT COUNT(*) FROM ' . Settings::TABLE)->fetchColumn());
    }

    public function testSaveCastsOnLoad(): void
    {
        $db = TestDatabases::module();
        Settings::save($db, ['max_tries' => 5, 'operator_number' => '2000']);
        $settings = Settings::load($db);

        self::assertSame(5, $settings['max_tries']);
        self::assertSame('2000', $settings['operator_number']);
        self::assertSame(-1, $settings['welcome_prompt_id']);
    }

    public function testModelColumnsMatchTheDefaults(): void
    {
        $columns = array_column(TestDatabases::module()->query('PRAGMA table_info(' . Settings::TABLE . ')')->fetchAll(), 'name');

        self::assertSame(['id', ...array_keys(Settings::DEFAULTS)], $columns);
    }

    public function testUnknownSettingRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Settings::save(TestDatabases::module(), ['no_such' => '1']);
    }
}
