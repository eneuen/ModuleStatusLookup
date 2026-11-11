<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use Modules\ModuleStatusLookup\Lib\EditorException;
use Modules\ModuleStatusLookup\Lib\StatusEditor;
use Modules\ModuleStatusLookup\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusEditorTest extends TestCase
{
    private PDO $db;

    private StatusEditor $editor;

    protected function setUp(): void
    {
        $this->db = TestDatabases::module();
        $this->editor = new StatusEditor($this->db, static fn (int $id): bool => in_array($id, [5, 6], true), ['201', '2000']);
    }

    private static function input(array $override = []): array
    {
        return $override + ['code' => 'READY', 'name' => 'Ready for pickup', 'prompt_id' => '5', 'spoken_extra' => 'id',
            'after_action' => 'repeat', 'transfer_number' => ''];
    }

    private function records(string $code, int $n): void
    {
        $stmt = $this->db->prepare('INSERT INTO m_StatusLookupRecords (lookup_id, status_code) VALUES (?, ?)');
        for ($i = 0; $i < $n; $i++) {
            $stmt->execute([$code . $i, $code]);
        }
    }

    public function testAddListedInOrderWithRecordCounts(): void
    {
        $a = $this->editor->save(self::input());
        $b = $this->editor->save(self::input(['code' => 'parts', 'name' => '  Waiting   on parts ', 'after_action' => 'transfer',
            'transfer_number' => '201', 'spoken_extra' => 'none']));
        $this->records('READY', 2);

        $list = $this->editor->list();
        self::assertSame([$a, $b], array_column($list, 'id'));
        self::assertSame('PARTS', $list[1]['code']);
        self::assertSame('Waiting on parts', $list[1]['name']);
        self::assertSame('201', $list[1]['transfer_number']);
        self::assertSame([2, 0], array_column($list, 'records'));
    }

    public function testRequirements(): void
    {
        $id = $this->editor->save(self::input(['requires_pin' => true, 'requires_callerid' => 'false']));
        $row = $this->editor->list()[0];
        self::assertSame([true, false], [$row['requires_pin'], $row['requires_callerid']]);
        $this->editor->save(self::input(['id' => $id, 'requires_pin' => false, 'requires_callerid' => 'true']));
        $row = $this->editor->list()[0];
        self::assertSame([false, true], [$row['requires_pin'], $row['requires_callerid']]);
    }

    public function testTransferNumberOnlyKeptForTransfers(): void
    {
        $id = $this->editor->save(self::input(['transfer_number' => '201']));
        self::assertSame('', $this->editor->list()[0]['transfer_number']);
        self::assertSame($id, $this->editor->save(self::input(['id' => (string)$id, 'name' => 'Ready'])));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refused(): iterable
    {
        yield 'code with a dash' => [['code' => 'NOT-READY'], 'code_format'];
        yield 'code too long' => [['code' => str_repeat('A', 33)], 'code_format'];
        yield 'empty name' => [['name' => ' '], 'name'];
        yield 'name too long' => [['name' => str_repeat('x', 61)], 'name'];
        yield 'control character in the name' => [['name' => "Ready\x07"], 'name'];
        yield 'no prompt' => [['prompt_id' => ''], 'status_prompt'];
        yield 'built-in prompt' => [['prompt_id' => '-1'], 'status_prompt'];
        yield 'gone sound file' => [['prompt_id' => '9'], 'status_prompt'];
        yield 'unknown extra' => [['spoken_extra' => 'amount'], 'bad_request'];
        yield 'unknown after-action' => [['after_action' => 'dial'], 'bad_request'];
        yield 'transfer without a destination' => [['after_action' => 'transfer'], 'status_destination'];
        yield 'transfer to an outside number' => [['after_action' => 'transfer', 'transfer_number' => '0049301234'], 'status_destination'];
        yield 'unknown id' => [['id' => '99'], 'status_gone'];
    }

    #[DataProvider('refused')]
    public function testRefused(array $override, string $code): void
    {
        try {
            $this->editor->save(self::input($override));
            self::fail('accepted');
        } catch (EditorException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    public function testCodesAreUnique(): void
    {
        $this->editor->save(self::input());
        $this->expectException(EditorException::class);
        $this->editor->save(self::input(['code' => 'ready']));
    }

    public function testRenamingACodeMovesItsRecords(): void
    {
        $id = $this->editor->save(self::input());
        $this->records('READY', 3);
        $this->editor->save(self::input(['id' => $id, 'code' => 'COLLECT']));

        self::assertSame(3, (int)$this->db->query("SELECT COUNT(*) FROM m_StatusLookupRecords WHERE status_code = 'COLLECT'")->fetchColumn());
    }

    public function testDeleteInUseNeedsAStatusToMoveTo(): void
    {
        $ready = $this->editor->save(self::input());
        $parts = $this->editor->save(self::input(['code' => 'PARTS']));
        $this->records('READY', 2);

        try {
            $this->editor->delete((string)$ready);
            self::fail('deleted');
        } catch (EditorException $e) {
            self::assertSame('status_in_use', $e->errorCode);
            self::assertSame(2, $e->params['n']);
        }
        self::assertSame(2, $this->editor->delete((string)$ready, (string)$parts));
        self::assertSame(['PARTS'], array_column($this->editor->list(), 'code'));
        self::assertSame(2, $this->editor->list()[0]['records']);
    }

    public function testDeleteUnusedAndMoveToItselfRefused(): void
    {
        $ready = $this->editor->save(self::input());
        $this->expectException(EditorException::class);
        $this->editor->delete($ready, $ready);
    }

    public function testMove(): void
    {
        $a = $this->editor->save(self::input());
        $b = $this->editor->save(self::input(['code' => 'B']));
        $c = $this->editor->save(self::input(['code' => 'C']));
        $this->editor->move($c, -1);
        self::assertSame([$a, $c, $b], array_column($this->editor->list(), 'id'));
        $this->editor->move($c, 0);    // no step: nothing moves
        self::assertSame([$a, $c, $b], array_column($this->editor->list(), 'id'));
        $this->editor->move($a, -1);   // already first
        $this->editor->move($b, 1);    // already last
        self::assertSame([$a, $c, $b], array_column($this->editor->list(), 'id'));
    }

    public function testStatusLimit(): void
    {
        for ($i = 0; $i < StatusEditor::MAX_STATUSES; $i++) {
            $this->editor->save(self::input(['code' => "S$i"]));
        }
        $this->expectException(EditorException::class);
        $this->editor->save(self::input(['code' => 'ONE_MORE']));
    }
}
