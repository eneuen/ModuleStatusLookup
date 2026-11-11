<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use Modules\ModuleStatusLookup\Lib\EditorException;
use Modules\ModuleStatusLookup\Lib\Importer;
use Modules\ModuleStatusLookup\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImporterTest extends TestCase
{
    private const int NOW = 1794398400;

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = TestDatabases::module();
        $this->db->exec("INSERT INTO m_StatusLookupRecords (lookup_id, status_code, note, expires_on, status_date, updated_at, source)
            VALUES ('10445', 'READY', '=Toyota', '', '', 1, 'form'), ('20001', 'PARTS', '', '', '', 1, 'form'), ('30001', 'READY', '', '', '', 1, 'form')");
    }

    private static function importer(string $csv): Importer
    {
        return new Importer($csv, [4, 10], ['READY', 'PARTS', 'SHIPPED']);
    }

    private function ids(): array
    {
        return $this->db->query('SELECT lookup_id FROM m_StatusLookupRecords ORDER BY lookup_id')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testPreviewAndApplyUpdate(): void
    {
        $csv = "\xEF\xBB\xBFlookup_id,status_code,note,pin,expires,date\r\n"
            . "10445,ready,'=Toyota,,,\r\n"                     // unchanged: case doesn't matter, nor the export's formula guard
            . "20001,SHIPPED,\"Box 3, shelf\",,2026-11-30,2026-11-12\r\n"   // updated
            . "00451,PARTS,\"two\nlines\",,,\r\n"              // added; a quoted note over two lines
            . "\r\n";
        $importer = self::importer($csv);
        $preview = $importer->preview($this->db, 'update');
        self::assertSame(['added' => 1, 'updated' => 1, 'unchanged' => 1, 'deleted' => 0, 'kept' => 0, 'rejected' => 0, 'report' => []], $preview);
        self::assertSame(['10445', '20001', '30001'], $this->ids(), 'a preview changes nothing');

        $importer->apply($this->db, 'update', 'admin', 'shop.csv', self::NOW);
        self::assertSame(['00451', '10445', '20001', '30001'], $this->ids());
        $row = $this->db->query("SELECT * FROM m_StatusLookupRecords WHERE lookup_id = '20001'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['SHIPPED', 'Box 3, shelf', '2026-11-30', '2026-11-12', 'csv', 'admin'],
            [$row['status_code'], $row['note'], $row['expires_on'], $row['status_date'], $row['source'], $row['updated_by']]);
        self::assertSame('two lines', $this->db->query("SELECT note FROM m_StatusLookupRecords WHERE lookup_id = '00451'")->fetchColumn());
        // The unchanged record keeps its last change.
        self::assertSame(1, (int)$this->db->query("SELECT updated_at FROM m_StatusLookupRecords WHERE lookup_id = '10445'")->fetchColumn());

        $history = Importer::history($this->db);
        self::assertSame(['shop.csv', 'update', 1, 1, 1, 0, 0], [$history[0]['file_name'], $history[0]['mode'], $history[0]['added'],
            $history[0]['updated'], $history[0]['unchanged'], $history[0]['deleted'], $history[0]['rejected']]);
    }

    public function testReplaceDeletesRecordsNotInTheFile(): void
    {
        $importer = self::importer("status_code;lookup_id\nREADY;10445\n");   // semicolons, columns in another order
        self::assertSame(2, $importer->preview($this->db, 'replace')['deleted']);
        $importer->apply($this->db, 'replace', 'admin', 'x.csv', self::NOW);
        self::assertSame(['10445'], $this->ids());
    }

    public function testReplaceWithNoUsableRowIsRefused(): void
    {
        $importer = self::importer("lookup_id,status_code\n12,READY\n");
        try {
            $importer->apply($this->db, 'replace', 'admin', 'x.csv', self::NOW);
            self::fail('applied');
        } catch (EditorException $e) {
            self::assertSame('replace_empty', $e->errorCode);
        }
        self::assertSame(['10445', '20001', '30001'], $this->ids());
    }

    public function testRowsRejectedWithRowNumberAndReason(): void
    {
        $csv = "lookup_id,status_code,note,pin,expires,date\n"
            . "TK-10450,READY,,,,\n"          // row 2: kept, as 10450
            . "123,READY,,,,\n"               // row 3: too short
            . ",READY,,,,\n"                  // row 4: no ID
            . "10450,READY,,,,\n"             // row 5: duplicate of row 2
            . "10451,GONE,,,,\n"              // row 6: unknown status
            . "10452,READY,,4821,,\n"         // row 7: PIN not supported yet
            . "10453,READY,,,30/11/2026,\n"   // row 8: bad last day
            . "10454,READY,,,,2026-02-30\n"   // row 9: date that doesn't exist
            . '10455,READY,' . str_repeat('x', 201) . ",,,\n";   // row 10: note too long
        $preview = self::importer($csv)->preview($this->db, 'update');

        self::assertSame(1, $preview['added']);
        self::assertSame(8, $preview['rejected']);
        self::assertSame([
            ['row' => 3, 'reason' => 'id_length', 'id' => '123'],
            ['row' => 4, 'reason' => 'no_id', 'id' => ''],
            ['row' => 5, 'reason' => 'duplicate', 'id' => '10450 (2)'],
            ['row' => 6, 'reason' => 'status', 'id' => '10451'],
            ['row' => 7, 'reason' => 'pin', 'id' => '10452'],
            ['row' => 8, 'reason' => 'expires', 'id' => '10453'],
            ['row' => 9, 'reason' => 'date', 'id' => '10454'],
            ['row' => 10, 'reason' => 'note', 'id' => '10455'],
        ], $preview['report']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badFiles(): iterable
    {
        yield 'empty' => ['', 'file_empty'];
        yield 'no status column' => ["lookup_id,note\n10445,x\n", 'file_header'];
        yield 'not UTF-8' => ["lookup_id,status_code,note\n10445,READY,caf\xE9\n", 'file_encoding'];
        yield 'too big' => [str_repeat('x', Importer::MAX_BYTES + 1), 'file_size'];
    }

    #[DataProvider('badFiles')]
    public function testBadFiles(string $csv, string $code): void
    {
        try {
            self::importer($csv);
            self::fail('accepted');
        } catch (EditorException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    public function testRowLimit(): void
    {
        $csv = "lookup_id,status_code\n" . str_repeat("10445,READY\n", Importer::MAX_ROWS + 1);
        try {
            self::importer($csv);
            self::fail('accepted');
        } catch (EditorException $e) {
            self::assertSame('file_rows', $e->errorCode);
        }
    }

    public function testReplaceKeepsRecordsWhoseRowWasLeftOut(): void
    {
        // 20001 has a typo in its date: its record must not be deleted.
        $importer = self::importer("lookup_id,status_code,expires\n10445,READY,\n20001,PARTS,30/11/2026\n");
        $preview = $importer->preview($this->db, 'replace');
        self::assertSame([1, 1, 1], [$preview['deleted'], $preview['kept'], $preview['rejected']]);
        $importer->apply($this->db, 'replace', 'admin', 'x.csv', self::NOW);
        self::assertSame(['10445', '20001'], $this->ids());
    }

    public function testBlankLinesBeforeTheHeader(): void
    {
        self::assertSame(1, self::importer("\r\n\r\nlookup_id,status_code\n40001,READY\n")->preview($this->db, 'update')['added']);
    }

    public function testNoteStartingWithAnApostropheSurvivesTheRoundTrip(): void
    {
        $this->db->exec("UPDATE m_StatusLookupRecords SET note = '''=quoted' WHERE lookup_id = '20001'");
        $out = '';
        (new \Modules\ModuleStatusLookup\Lib\RecordEditor($this->db, new \DateTimeZone('UTC')))
            ->export([], self::NOW, function (string $line) use (&$out): void {
                $out .= $line;
            });
        self::assertStringContainsString("''=quoted", $out);
        self::assertSame(3, self::importer($out)->preview($this->db, 'replace')['unchanged']);
    }

    public function testReportIsCapped(): void
    {
        $csv = "lookup_id,status_code\n" . str_repeat("1,READY\n", Importer::MAX_REPORT + 10);
        $preview = self::importer($csv)->preview($this->db, 'update');
        self::assertSame(Importer::MAX_REPORT + 10, $preview['rejected']);
        self::assertCount(Importer::MAX_REPORT, $preview['report']);
    }

    public function testUnknownModeRefused(): void
    {
        $this->expectException(EditorException::class);
        self::importer("lookup_id,status_code\n10445,READY\n")->preview($this->db, 'merge');
    }

    public function testExportedFileImportsBackUnchanged(): void
    {
        $out = '';
        (new \Modules\ModuleStatusLookup\Lib\RecordEditor($this->db, new \DateTimeZone('UTC')))
            ->export([], self::NOW, function (string $line) use (&$out): void {
                $out .= $line;
            });
        $preview = self::importer($out)->preview($this->db, 'replace');
        self::assertSame(['added' => 0, 'updated' => 0, 'unchanged' => 3, 'deleted' => 0, 'kept' => 0, 'rejected' => 0], array_slice($preview, 0, 6));
    }
}
