<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use Modules\ModuleStatusLookup\Lib\CallFlowEditor;
use Modules\ModuleStatusLookup\Lib\EditorException;
use Modules\ModuleStatusLookup\Lib\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CallFlowEditorTest extends TestCase
{
    /** Built-in prompts and sound file 5 play; nothing else does. */
    private static function editor(): CallFlowEditor
    {
        return new CallFlowEditor(static fn (int $id): bool => $id < 0 || $id === 5, ['201', '2000', '3100']);
    }

    private static function current(array $override = []): array
    {
        return $override + ['internal_number' => '2300'] + Settings::DEFAULTS;
    }

    public function testValidChangeIsCast(): void
    {
        $out = self::editor()->change([
            'welcome_prompt_id' => '0', 'enter_id_prompt_id' => '5', 'max_tries' => '4', 'digit_timeout' => 7,
            'operator_number' => ' 201 ', 'too_many_tries_number' => '', 'unknown' => 'x',
        ], self::current());

        self::assertSame(['welcome_prompt_id' => 0, 'enter_id_prompt_id' => 5, 'digit_timeout' => 7, 'max_tries' => 4,
            'operator_number' => '201', 'too_many_tries_number' => ''], $out);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refused(): iterable
    {
        yield 'unplayable prompt' => [['enter_id_prompt_id' => '6'], 'prompt'];
        yield 'only the welcome may be none' => [['goodbye_prompt_id' => '0'], 'prompt'];
        yield 'tries too high' => [['max_tries' => '10'], 'range'];
        yield 'ID too short' => [['id_min_length' => '1'], 'range'];
        yield 'not a number' => [['digit_timeout' => '5s'], 'whole_number'];
        yield 'array' => [['digit_timeout' => ['5']], 'whole_number'];
        yield 'unknown destination' => [['operator_number' => '999'], 'destination'];
        yield 'dialplan in a destination' => [['fault_number' => '201,1,Hangup()'], 'destination'];
        yield 'min over max' => [['id_min_length' => '8', 'id_max_length' => '6'], 'lengths'];
        yield 'switch on without an operator' => [['active' => true], 'not_ready'];
    }

    #[DataProvider('refused')]
    public function testRefused(array $input, string $code): void
    {
        try {
            self::editor()->change($input, self::current());
            self::fail('accepted');
        } catch (EditorException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    public function testMinOverMaxAgainstTheCurrentValue(): void
    {
        $this->expectException(EditorException::class);
        self::editor()->change(['id_min_length' => '12'], self::current());
    }

    public function testSwitchOnWhenReady(): void
    {
        self::assertSame(['active' => '1'], self::editor()->change(['active' => 'true'], self::current(['operator_number' => '201'])));
        self::assertSame(['active' => '0'], self::editor()->change(['active' => false], self::current(['operator_number' => '201', 'active' => '1'])));
    }

    public function testAnOnLineCantBreakItsOwnSetup(): void
    {
        try {
            self::editor()->change(['operator_number' => ''], self::current(['operator_number' => '201', 'active' => '1']));
            self::fail('accepted');
        } catch (EditorException $e) {
            self::assertSame('not_ready', $e->errorCode);
            self::assertSame('no_operator', $e->params['problems']);
        }
    }

    public function testProblems(): void
    {
        $e = self::editor();
        self::assertSame([], $e->problems(self::current(['operator_number' => '201'])));
        self::assertSame(['no_number', 'no_operator', 'prompt_enter_id', 'gone_fault_number'],
            $e->problems(self::current(['internal_number' => '', 'enter_id_prompt_id' => 6, 'fault_number' => '777'])));
        // No welcome is fine.
        self::assertSame([], $e->problems(self::current(['operator_number' => '201', 'welcome_prompt_id' => 0])));
    }
}
