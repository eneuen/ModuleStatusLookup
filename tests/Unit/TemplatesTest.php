<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use Modules\ModuleStatusLookup\Lib\EditorException;
use Modules\ModuleStatusLookup\Lib\Settings;
use Modules\ModuleStatusLookup\Lib\Templates;
use Modules\ModuleStatusLookup\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;

final class TemplatesTest extends TestCase
{
    private const string DIR = __DIR__ . '/../../Setup/templates';

    public function testShippedTemplatesAreAllValid(): void
    {
        $files = glob(self::DIR . '/*.json');
        $list = (new Templates(self::DIR))->all('en-en');

        self::assertCount(count($files), $list, 'every shipped file is a valid template');
        self::assertSame(['repair', 'auto', 'pharmacy', 'retail', 'blank'], array_column($list, 'key'));
        self::assertSame('IT and Device Repair', $list[0]['name']);
        self::assertSame([2, 10], $list[4]['id_length']);
    }

    public function testRussianTexts(): void
    {
        $repair = (new Templates(self::DIR))->all('ru-ru')[0];
        self::assertSame('Ремонт техники', $repair['name']);
        self::assertSame('Готово к выдаче', $repair['statuses'][4]['name']);
        // Other languages get English.
        self::assertSame('Ready for pickup', (new Templates(self::DIR))->all('de-de')[0]['statuses'][4]['name']);
    }

    public function testApplyAddsStatusesAndSetsIdLengthsOnce(): void
    {
        $db = TestDatabases::module();
        Settings::ensureRow($db);
        $templates = new Templates(self::DIR);

        $result = $templates->apply($db, 'repair', 'en-en', '201');
        self::assertSame(['RECEIVED', 'DIAGNOSING', 'PARTS', 'REPAIRING', 'READY', 'CANNOT_REPAIR'], $result['added']);
        $settings = Settings::load($db);
        self::assertSame(['repair', 4, 6], [$settings['template'], $settings['id_min_length'], $settings['id_max_length']]);
        $cannot = $db->query("SELECT * FROM m_StatusLookupStatuses WHERE code = 'CANNOT_REPAIR'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['transfer', '201', null], [$cannot['after_action'], $cannot['transfer_number'], $cannot['prompt_id']]);
        self::assertStringContainsString('Please speak to us', $cannot['script']);

        // A second template adds only what's missing and leaves the ID lengths alone.
        $result = $templates->apply($db, 'auto', 'en-en', '');
        self::assertSame(['PARTS', 'READY'], $result['kept']);
        self::assertSame(['CHECKED_IN', 'INSPECTING', 'NEEDS_APPROVAL', 'IN_SERVICE'], $result['added']);
        self::assertSame([4, 6], [Settings::load($db)['id_min_length'], Settings::load($db)['id_max_length']]);
        // Without an operator, "to a person" becomes the repeat menu (0 reaches the operator).
        self::assertSame('repeat', $db->query("SELECT after_action FROM m_StatusLookupStatuses WHERE code = 'NEEDS_APPROVAL'")->fetchColumn());
        self::assertSame(10, (int)$db->query('SELECT MAX(sort_order) FROM m_StatusLookupStatuses')->fetchColumn());
    }

    public function testIdLengthsLeftAloneWhenRecordsExist(): void
    {
        $db = TestDatabases::module();
        Settings::ensureRow($db);
        $db->exec("INSERT INTO m_StatusLookupRecords (lookup_id, status_code) VALUES ('1234567', 'X')");
        (new Templates(self::DIR))->apply($db, 'auto', 'en-en', '');

        $settings = Settings::load($db);
        self::assertSame(['auto', 4, 10], [$settings['template'], $settings['id_min_length'], $settings['id_max_length']]);
    }

    public function testUnknownTemplateRefused(): void
    {
        foreach (['nope', '../templates/repair', 'REPAIR'] as $key) {
            try {
                (new Templates(self::DIR))->apply(TestDatabases::module(), $key, 'en-en', '');
                self::fail("accepted $key");
            } catch (EditorException $e) {
                self::assertSame('template', $e->errorCode);
            }
        }
    }

    public function testInvalidFilesAreSkipped(): void
    {
        $dir = sys_get_temp_dir() . '/sl-templates-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $good = json_decode((string)file_get_contents(self::DIR . '/repair.json'), true);
        $write = static function (string $name, mixed $data) use ($dir): void {
            file_put_contents("$dir/$name.json", is_string($data) ? $data : json_encode($data));
        };
        $write('repair', $good);
        $write('broken', '{not json');
        $write('other', ['key' => 'wrong'] + $good);                                        // key doesn't match the file name
        $bad = $good;
        $bad['key'] = 'badcode';
        $bad['statuses'][0]['code'] = 'not-a-code';
        $write('badcode', $bad);
        $bad = $good;
        $bad['key'] = 'badafter';
        $bad['statuses'][0]['after'] = 'dial';
        $write('badafter', $bad);
        $bad = $good;
        $bad['key'] = 'badlength';
        $bad['id_length'] = [1, 30];
        $write('badlength', $bad);
        try {
            self::assertSame(['repair'], array_column((new Templates($dir))->all('en-en'), 'key'));
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }
}
