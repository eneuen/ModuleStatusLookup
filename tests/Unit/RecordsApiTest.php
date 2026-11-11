<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use DateTimeZone;
use Modules\ModuleStatusLookup\Lib\EditorException;
use Modules\ModuleStatusLookup\Lib\Pin;
use Modules\ModuleStatusLookup\Lib\RecordsApi;
use Modules\ModuleStatusLookup\Lib\Settings;
use Modules\ModuleStatusLookup\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;

final class RecordsApiTest extends TestCase
{
    /** 2026-11-11 12:00 UTC. */
    private const int NOW = 1794398400;

    private PDO $db;

    private RecordsApi $api;

    private Pin $pin;

    protected function setUp(): void
    {
        $this->db = TestDatabases::module();
        Settings::ensureRow($this->db);
        Settings::save($this->db, ['id_min_length' => 4, 'id_max_length' => 10]);
        $this->db->exec("INSERT INTO m_StatusLookupStatuses (code, name, requires_pin) VALUES ('READY', 'Ready', '1'), ('PARTS', 'Waiting for parts', '0')");
        $this->pin = new Pin(str_repeat('k', 64));
        $this->api = new RecordsApi($this->db, new DateTimeZone('Europe/Berlin'), $this->pin);
    }

    public function testPutCreatesThenChangesOnlyTheFieldsSent(): void
    {
        $first = $this->api->put('00451', ['status_code' => 'PARTS', 'note' => 'iPhone 13', 'expires_on' => '2026-12-01', 'pin' => '4821'], 'API key: Shop', self::NOW);
        self::assertTrue($first['created']);
        self::assertSame(['lookup_id' => '00451', 'status_code' => 'PARTS', 'note' => 'iPhone 13', 'status_date' => '', 'expires_on' => '2026-12-01',
            'phone' => '', 'has_pin' => true, 'updated_at' => '2026-11-11T13:00:00+01:00', 'updated_by' => 'API key: Shop', 'source' => 'api'], $first['record']);

        $second = $this->api->put('00451', ['status_code' => 'READY', 'expires_on' => ''], 'API key: Shop', self::NOW + 60);
        self::assertFalse($second['created']);
        self::assertSame('READY', $second['record']['status_code']);
        self::assertSame('iPhone 13', $second['record']['note'], 'a field left out keeps its value');
        self::assertSame('', $second['record']['expires_on'], 'an empty string clears');
        self::assertTrue($second['record']['has_pin'], 'the PIN is kept');
        $hash = $this->db->query("SELECT pin_hash FROM m_StatusLookupRecords WHERE lookup_id = '00451'")->fetchColumn();
        self::assertTrue($this->pin->matches('4821', (string)$hash));

        self::assertFalse($this->api->put('00451', ['remove_pin' => true], 'x', self::NOW)['record']['has_pin']);
    }

    public function testPutRefusals(): void
    {
        foreach ([
            ['10445', [], 'status_required'],
            ['10445', ['status_code' => 'NOPE'], 'record_status'],
            ['10445', ['status_code' => 'READY', 'colour' => 'red'], 'unknown_fields'],
            ['10445', ['status_code' => ['READY']], 'field_type'],
            ['10445', ['status_code' => 'READY', 'pin' => '12'], 'pin'],
            ['104', ['status_code' => 'READY'], 'lookup_id'],
            ['10445', ['status_code' => true], 'field_type'],
            ['10445', ['status_code' => 'READY', 'note' => 'https://track.example/1 shipped'], 'note_http'],
            ['10445', ['status_code' => 'READY', 'phone' => '12345'], 'phone'],
        ] as [$id, $data, $code]) {
            try {
                $this->api->put($id, $data, 'x', self::NOW);
                self::fail("$code not refused");
            } catch (EditorException $e) {
                self::assertSame($code, $e->getMessage());
            }
        }
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM m_StatusLookupRecords')->fetchColumn());
    }

    public function testGetListDelete(): void
    {
        $this->api->put('10445', ['status_code' => 'READY'], 'x', self::NOW);
        $this->api->put('10446', ['status_code' => 'PARTS'], 'x', self::NOW + 1);
        self::assertSame('READY', $this->api->get('10445')['status_code']);
        self::assertNull($this->api->get('99999'));

        $list = $this->api->list(['status' => 'PARTS'], self::NOW);
        self::assertSame(['10446'], array_column($list['records'], 'lookup_id'));
        self::assertSame(['page' => 1, 'pages' => 1, 'total' => 1, 'page_size' => 50], $list['pagination']);
        self::assertArrayNotHasKey('id', $list['records'][0]);

        self::assertTrue($this->api->delete('10445'));
        self::assertFalse($this->api->delete('10445'));
    }

    public function testImportDryRunThenApply(): void
    {
        $csv = "lookup_id,status_code,pin\r\n10445,READY,4821\r\n10446,PARTS,\r\n12,READY,\r\n";
        $preview = $this->api->import($csv, 'update', true, 'API key: Shop', self::NOW);
        self::assertSame([2, 1, true], [$preview['added'], $preview['rejected'], $preview['dry_run']]);
        self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM m_StatusLookupRecords')->fetchColumn());

        $done = $this->api->import($csv, 'update', false, 'API key: Shop', self::NOW);
        self::assertSame([2, false], [$done['added'], $done['dry_run']]);
        self::assertSame(['api'], $this->db->query('SELECT DISTINCT source FROM m_StatusLookupRecords')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame('API', $this->db->query('SELECT file_name FROM m_StatusLookupImports')->fetchColumn());
        self::assertTrue($this->api->get('10445')['has_pin']);
    }

    public function testImportRefusesNoCsv(): void
    {
        $this->expectException(EditorException::class);
        $this->api->import(null, 'update', false, 'x', self::NOW);
    }

    public function testStatusesAndStats(): void
    {
        $this->api->put('10445', ['status_code' => 'READY'], 'x', self::NOW);
        self::assertSame([
            ['code' => 'READY', 'name' => 'Ready', 'records' => 1, 'requires_pin' => true, 'requires_callerid' => false],
            ['code' => 'PARTS', 'name' => 'Waiting for parts', 'records' => 0, 'requires_pin' => false, 'requires_callerid' => false],
        ], $this->api->statuses());
        $stats = $this->api->stats('2026-11-01', '2026-11-11');
        self::assertSame(0, $stats['totals']['calls']);
        self::assertArrayNotHasKey('notFound', $stats);
    }
}
