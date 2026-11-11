<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use DateTimeZone;
use Modules\ModuleStatusLookup\Lib\EditorException;
use Modules\ModuleStatusLookup\Lib\Pin;
use Modules\ModuleStatusLookup\Lib\RecordEditor;
use Modules\ModuleStatusLookup\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecordEditorTest extends TestCase
{
    /** 2026-11-11 12:00 UTC. */
    private const int NOW = 1794398400;

    private PDO $db;

    private RecordEditor $editor;

    protected function setUp(): void
    {
        $this->db = TestDatabases::module();
        $this->db->exec("INSERT INTO m_StatusLookupStatuses (code, name) VALUES ('READY', 'Ready'), ('PARTS', 'Parts')");
        $this->editor = new RecordEditor($this->db, new DateTimeZone('Europe/Berlin'), new Pin(str_repeat('k', 64)));
    }

    private function add(string $id, array $override = [], int $at = self::NOW): int
    {
        return $this->editor->save($override + ['lookup_id' => $id, 'status_code' => 'READY', 'note' => '', 'status_date' => '',
            'expires_on' => ''], [4, 10], 'admin', $at);
    }

    public function testAddAndList(): void
    {
        $this->add('10445', ['note' => "Toyota\nVios", 'status_date' => '2026-11-12', 'expires_on' => '2026-11-30']);
        $this->add('00451', [], self::NOW + 60);

        $list = $this->editor->list([], self::NOW + 120);
        self::assertSame(2, $list['total']);
        self::assertSame(['00451', '10445'], array_column($list['rows'], 'lookup_id'));   // newest change first
        self::assertSame('Toyota Vios', $list['rows'][1]['note']);
        self::assertSame('admin', $list['rows'][1]['updated_by']);
        self::assertSame('form', $list['rows'][1]['source']);
        self::assertSame('2026-11-11', $list['today']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refused(): iterable
    {
        yield 'ID too short' => [['lookup_id' => '123'], 'lookup_id'];
        yield 'ID too long' => [['lookup_id' => '12345678901'], 'lookup_id'];
        yield 'ID with a dash' => [['lookup_id' => '104-45'], 'lookup_id'];
        yield 'unknown status' => [['status_code' => 'GONE'], 'record_status'];
        yield 'note too long' => [['note' => str_repeat('x', 201)], 'note'];
        yield 'date that does not exist' => [['expires_on' => '2026-02-30'], 'date'];
        yield 'date in another format' => [['status_date' => '30/11/2026'], 'date'];
        yield 'unknown record' => [['id' => '99'], 'record_gone'];
    }

    #[DataProvider('refused')]
    public function testRefused(array $override, string $code): void
    {
        try {
            $this->add($override['lookup_id'] ?? '10445', $override);
            self::fail('accepted');
        } catch (EditorException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    public function testIdsAreUnique(): void
    {
        $this->add('10445');
        $this->expectException(EditorException::class);
        $this->add('10445');
    }

    public function testEdit(): void
    {
        $id = $this->add('10445');
        $this->editor->save(['id' => (string)$id, 'lookup_id' => '10446', 'status_code' => 'PARTS', 'note' => 'x'], [4, 10], 'eve', self::NOW + 5);
        $row = $this->editor->list([], self::NOW)['rows'][0];

        self::assertSame(['10446', 'PARTS', 'eve'], [$row['lookup_id'], $row['status_code'], $row['updated_by']]);
        self::assertSame(self::NOW + 5, $row['updated_at']);
    }

    public function testFilters(): void
    {
        $this->add('10445', ['note' => 'iPhone 13 screen']);
        $this->add('10446', ['status_code' => 'PARTS', 'expires_on' => '2026-11-01'], self::NOW - 40 * 86400);
        $this->add('20001', ['note' => '100% done_x']);

        $ids = fn (array $q): array => array_column($this->editor->list($q, self::NOW)['rows'], 'lookup_id');
        // IDs match from the start; newest change first.
        self::assertSame(['10445', '10446'], $ids(['search' => '1044']));
        self::assertSame(['10445'], $ids(['search' => 'iphone']));
        self::assertSame(['20001'], $ids(['search' => '100%']));
        self::assertSame([], $ids(['search' => '_x%']));
        self::assertSame(['10446'], $ids(['status' => 'PARTS']));
        self::assertSame(['10446'], $ids(['stale' => '30']));
        self::assertSame(['10446'], $ids(['expired' => '1']));
    }

    public function testBadFiltersRefused(): void
    {
        foreach ([['status' => "READY' OR 1"], ['stale' => '5']] as $q) {
            try {
                $this->editor->list($q, self::NOW);
                self::fail('accepted');
            } catch (EditorException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPages(): void
    {
        $stmt = $this->db->prepare("INSERT INTO m_StatusLookupRecords (lookup_id, status_code, updated_at) VALUES (?, 'READY', ?)");
        for ($i = 0; $i < 120; $i++) {
            $stmt->execute([sprintf('%05d', $i), $i]);
        }
        $last = $this->editor->list(['page' => '3'], self::NOW);
        self::assertSame([3, 3, 120, 20], [$last['page'], $last['pages'], $last['total'], count($last['rows'])]);
        self::assertSame(3, $this->editor->list(['page' => '99'], self::NOW)['page']);
    }

    public function testBulkStatusAndDelete(): void
    {
        $a = $this->add('10445');
        $b = $this->add('10446');
        $c = $this->add('10447');

        self::assertSame(2, $this->editor->changeStatus([(string)$a, $b, $a], 'PARTS', 'eve', self::NOW + 1));
        self::assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM m_StatusLookupRecords WHERE status_code = 'PARTS'")->fetchColumn());
        self::assertSame(2, $this->editor->delete([$a, (string)$c]));
        self::assertSame(['10446'], array_column($this->editor->list([], self::NOW)['rows'], 'lookup_id'));
    }

    public function testBulkRefusals(): void
    {
        foreach ([fn () => $this->editor->delete([]), fn () => $this->editor->delete('1'), fn () => $this->editor->delete(['x']),
            fn () => $this->editor->changeStatus([1], 'NOPE', 'eve', self::NOW), fn () => $this->editor->delete(range(1, 1001))] as $call) {
            try {
                $call();
                self::fail('accepted');
            } catch (EditorException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPinIsStoredAsAHashKeptWhenEmptyAndRemovable(): void
    {
        $id = $this->add('10445', ['pin' => '4821', 'phone' => '+49 (30) 123-4567']);
        $hash = fn (): string => (string)$this->db->query("SELECT pin_hash FROM m_StatusLookupRecords WHERE id = $id")->fetchColumn();
        $first = $hash();
        self::assertTrue((new Pin(str_repeat('k', 64)))->matches('4821', $first));
        $row = $this->editor->list([], self::NOW)['rows'][0];
        self::assertSame([true, '49301234567'], [$row['has_pin'], $row['phone']]);
        self::assertArrayNotHasKey('pin_hash', $row);

        $this->add('10445', ['id' => (string)$id, 'note' => 'changed']);                       // no PIN given: kept
        self::assertSame($first, $hash());
        $this->add('10445', ['id' => (string)$id, 'remove_pin' => true]);
        self::assertSame('', $hash());
        self::assertFalse($this->editor->list([], self::NOW)['rows'][0]['has_pin']);
    }

    public function testBadPinAndPhoneRefused(): void
    {
        foreach ([['pin' => '123'], ['pin' => '12a4'], ['phone' => 'call me'], ['phone' => str_repeat('1', 21)]] as $bad) {
            try {
                $this->add('10445', $bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (EditorException $e) {
                self::assertContains($e->errorCode, ['pin', 'phone']);
            }
        }
        // Without a PIN key no PIN can be set.
        $this->expectException(EditorException::class);
        (new RecordEditor($this->db, new DateTimeZone('UTC')))->save(['lookup_id' => '10446', 'status_code' => 'READY', 'pin' => '4821'], [4, 10], 'a', 1);
    }

    public function testCountOutside(): void
    {
        $this->add('10445');
        $this->add('1044567');
        $this->add('104456789');

        self::assertSame(0, $this->editor->countOutside(4, 10));
        self::assertSame(2, $this->editor->countOutside(4, 6));
        self::assertSame(2, $this->editor->countOutside(6, 7));
    }

    public function testExportInTheImportFormat(): void
    {
        $this->add('00451', ['note' => '=HYPERLINK("x")', 'expires_on' => '2026-11-30']);
        $this->add('10445', ['note' => 'iPhone, 13', 'status_code' => 'PARTS', 'status_date' => '2026-11-12']);
        $out = '';
        $this->editor->export([], self::NOW, function (string $line) use (&$out): void {
            $out .= $line;
        });

        self::assertSame("lookup_id,status_code,note,pin,expires,date,phone\r\n"
            . "00451,READY,\"'=HYPERLINK(\"\"x\"\")\",,2026-11-30,,\r\n"
            . "10445,PARTS,\"iPhone, 13\",,,2026-11-12,\r\n", $out);
    }
}
