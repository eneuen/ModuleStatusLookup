<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit;

use Modules\ModuleStatusLookup\Lib\DialplanBuilder;
use Modules\ModuleStatusLookup\Lib\Settings;
use PHPUnit\Framework\TestCase;

final class DialplanBuilderTest extends TestCase
{
    private const string SOUNDS = '/offload/asterisk/sounds';

    private static function builder(array $settings = [], string $language = 'en-en', array $paths = []): DialplanBuilder
    {
        $settings += ['internal_number' => '2300', 'active' => '1', 'operator_number' => '2000'] + Settings::DEFAULTS;

        return new DialplanBuilder($settings, $language, self::SOUNDS, static fn (int $id): ?string => $paths[$id] ?? null);
    }

    public function testNumberInInternalAndTransfer(): void
    {
        $b = self::builder();

        self::assertSame("exten => 2300,1,Goto(status-lookup,s,1)\n", $b->internal());
        self::assertStringContainsString('exten => 2300,1,Set(__ISTRANSFER=transfer_)', $b->internalTransfer());
        self::assertStringContainsString('Goto(internal,2300,1)', $b->internalTransfer());
    }

    public function testNothingWithoutAValidNumber(): void
    {
        foreach (['', '2300,1,Hangup()', "2300\nexten"] as $number) {
            $b = self::builder(['internal_number' => $number]);
            self::assertSame('', $b->internal());
            self::assertSame('', $b->internalTransfer());
            self::assertSame('', $b->contexts());
        }
    }

    public function testEntryBehavesLikeTheCoreIvr(): void
    {
        $conf = self::builder()->contexts();

        self::assertStringContainsString("[status-lookup]\nexten => s,1,Set(SL_V=1)", $conf);
        self::assertStringContainsString('Set(APPEXTEN=2300)', $conf);
        self::assertStringContainsString('Gosub(dial_app,${EXTEN},1)', $conf);
        self::assertStringContainsString('Gosub(set-answer-state,${EXTEN},1)', $conf);
        self::assertStringContainsString('Set(CHANNEL(hangup_handler_push)=status-lookup-end,s,1)', $conf);
        self::assertStringContainsString('Set(CHANNEL(language)=en-en)', $conf);
        self::assertStringNotContainsString('NEED_MONITOR', $conf);
    }

    public function testOwnTimeoutReplacesTheRoutesAndIsClearedBeforeLeaving(): void
    {
        $conf = self::builder()->contexts();

        self::assertStringContainsString('Set(TIMEOUT(absolute)=120)', $conf);
        $clear = strpos($conf, 'Set(TIMEOUT(absolute)=0)');
        $goto = strpos($conf, 'Goto(internal,${SL_TARGET},1)');
        self::assertNotFalse($clear);
        self::assertNotFalse($goto);
        self::assertLessThan($goto, $clear);
        // The call is logged before the caller is sent on.
        self::assertLessThan($goto, strpos($conf, 'Gosub(status-lookup-end,s,1)'));
    }

    public function testBuiltInPromptsByName(): void
    {
        $conf = self::builder()->contexts();

        self::assertStringContainsString('Set(SL_ASK=modulestatuslookup-welcome&modulestatuslookup-enter-id)', $conf);
        self::assertStringContainsString('Read(SL_IN,${SL_ASK},,,1,5)', $conf);
        self::assertStringContainsString('Read(SL_KEY,modulestatuslookup-repeat,1,,1,5)', $conf);
    }

    public function testBuiltInPromptsInEnglishForOtherLanguages(): void
    {
        $conf = self::builder([], 'de_DE')->contexts();

        self::assertStringContainsString('Set(CHANNEL(language)=de-de)', $conf);
        self::assertStringContainsString('Set(SL_ASK=/offload/asterisk/sounds/en-en/modulestatuslookup-welcome&', $conf);
    }

    public function testOwnSoundFilesAndUnsafePathsRefused(): void
    {
        $paths = [5 => '/storage/media/custom/hello', 6 => '/storage/media/x),Hangup(', 7 => "/storage/a\nb"];
        $conf = self::builder(['welcome_prompt_id' => 5], 'en-en', $paths)->contexts();
        self::assertStringContainsString('Set(SL_ASK=/storage/media/custom/hello&modulestatuslookup-enter-id)', $conf);

        foreach ([6, 7, 99, 0] as $bad) {
            $b = self::builder(['enter_id_prompt_id' => $bad], 'en-en', $paths);
            self::assertFalse($b->isActive(), "prompt $bad");
            self::assertStringNotContainsString('Hangup(,', $b->contexts());
        }
    }

    public function testInactiveLineDoesntAnswerSoTheRouteMovesOn(): void
    {
        foreach ([['active' => '0'], ['operator_number' => ''], ['operator_number' => '20,1']] as $settings) {
            $b = self::builder($settings);
            $conf = $b->contexts();
            self::assertFalse($b->isActive());
            self::assertStringContainsString("Set(SL_OUTCOME=inactive)\n\tsame => n,Hangup()", $conf);
            // Still logged.
            self::assertStringContainsString('Set(CHANNEL(hangup_handler_push)=status-lookup-end,s,1)', $conf);
            foreach (['Answer()', 'set-answer-state', 'dial_app', 'Read(', 'Playback('] as $absent) {
                self::assertStringNotContainsString($absent, $conf);
            }
        }
    }

    public function testEveryVisitStartsAfresh(): void
    {
        $conf = self::builder()->contexts();

        foreach (['SL_LOGGED', 'SL_SENT', 'SL_LEFT', 'SL_TARGET', 'SL_RESULT', 'SL_ID', 'SL_STATUS'] as $var) {
            self::assertStringContainsString("Set($var=)", $conf);
        }
        self::assertLessThan(strpos($conf, 'hangup_handler_push'), strpos($conf, 'Set(SL_LOGGED=)'));
    }

    public function testAFailedLookupScriptCountsAsAFault(): void
    {
        $conf = self::builder()->contexts();

        self::assertStringContainsString("Set(SL_RESULT=fault)\n\tsame => n,Set(SL_ID=)\n\tsame => n,Set(SL_STATUS=)\n\tsame => n,Set(SL_PROMPT=)\n\tsame => n,AGI(status_lookup.php,lookup)", $conf);
    }

    public function testOnlyIdsNotFoundCountAsMisses(): void
    {
        self::assertStringContainsString('ExecIf($["${SL_RESULT}" != "invalid"]?Set(SL_MISSES=$[${SL_MISSES} + 1]))', self::builder()->contexts());
    }

    public function testTimeLimitSendsTheCallerOnInsteadOfHangingUp(): void
    {
        $conf = self::builder()->contexts();

        self::assertStringContainsString("exten => T,1,Verbose(", $conf);
        self::assertStringContainsString("Set(TIMEOUT(absolute)=30)\n\tsame => n,GotoIf(\$[\"\${SL_OUTCOME}\" == \"hit\"]?s,goodbye)\n\tsame => n,Goto(s,too_many)", $conf);
        // The time-limit extension belongs to the lookup context, before the hand-off context.
        self::assertLessThan(strpos($conf, '[status-lookup-end]'), strpos($conf, 'exten => T,1'));
    }

    public function testDestinationsDefaultToTheOperator(): void
    {
        $conf = self::builder()->contexts();
        // Operator, too many tries, rate limited, fault, and the fallback for a missing destination.
        self::assertSame(5, substr_count($conf, 'Set(SL_TARGET=2000)'));

        $conf = self::builder(['too_many_tries_number' => '2001', 'fault_number' => '2002'])->contexts();
        self::assertStringContainsString("too_many),Set(SL_OUTCOME=too_many)\n\tsame => n,Set(SL_TARGET=2001)", $conf);
        self::assertStringContainsString("fault),Set(SL_OUTCOME=fault)\n\tsame => n,Set(SL_TARGET=2002)", $conf);
    }

    public function testMissingDestinationGoesToTheFaultDestination(): void
    {
        $conf = self::builder(['fault_number' => '2002'])->contexts();

        self::assertStringContainsString('(leave),GotoIf($["${SL_TARGET}x" != "x" & ${DIALPLAN_EXISTS(internal,${SL_TARGET},1)}]?go)', $conf);
        self::assertStringContainsString("Set(SL_OUTCOME=fault)\n\tsame => n,Set(SL_TARGET=2002)\n\tsame => n,GotoIf(\${DIALPLAN_EXISTS(internal,\${SL_TARGET},1)}?go)", $conf);
    }

    public function testLimitsAreClamped(): void
    {
        $conf = self::builder(['max_tries' => 50, 'digit_timeout' => 0])->contexts();

        self::assertStringContainsString('GotoIf($[${SL_TRIES} >= 9]?too_many)', $conf);
        self::assertStringContainsString('Read(SL_IN,${SL_ASK},,,1,1)', $conf);
    }

    public function testCallersCanInterruptPrompts(): void
    {
        $conf = self::builder()->contexts();

        // A miss plays its message and the ID prompt in one Read(), so the caller can type over both.
        self::assertStringContainsString('Set(SL_MISSED=${IF($["${SL_RESULT}" == "invalid"]?modulestatuslookup-invalid:modulestatuslookup-not-found)})', $conf);
        self::assertStringContainsString('Set(SL_ASK=${SL_MISSED}&modulestatuslookup-enter-id)', $conf);
        // After the last try, the message is still played before the caller is sent on.
        self::assertStringContainsString("(last_miss),Playback(\${SL_MISSED})\n\tsame => n,Goto(too_many)", $conf);
        // 0 during the status sentence reaches a person.
        self::assertStringContainsString("(say),Read(SL_KEY,\${SL_PROMPT},1,,1,0.5)\n\tsame => n,GotoIf(\$[\"\${SL_KEY}\" == \"0\"]?operator)", $conf);
        self::assertStringNotContainsString('Playback(${SL_PROMPT})', $conf);
    }

    public function testWithoutAWelcomeTheFirstTryAsksStraightAway(): void
    {
        self::assertStringContainsString('Set(SL_ASK=modulestatuslookup-enter-id)', self::builder(['welcome_prompt_id' => 0])->contexts());
    }

    public function testZeroGoesToAPersonBeforeAnyLookup(): void
    {
        $conf = self::builder()->contexts();

        self::assertLessThan(strpos($conf, 'AGI(status_lookup.php,lookup)'), strpos($conf, 'GotoIf($["${SL_IN}" == "0"]?operator)'));
    }

    public function testHandOffRunsOnce(): void
    {
        $conf = self::builder()->contexts();

        self::assertStringContainsString("[status-lookup-end]\nexten => s,1,GotoIf(\$[\"\${SL_LOGGED}\" == \"1\"]?done)", $conf);
        self::assertSame(1, substr_count($conf, 'AGI(status_lookup_end.php)'));
        // Marked as logged only after the hand-off ran.
        self::assertLessThan(strpos($conf, 'Set(SL_LOGGED=1)'), strpos($conf, 'AGI(status_lookup_end.php)'));
        // The destination is only logged once the caller is handed over.
        self::assertStringContainsString('ExecIf($["${SL_SENT}" != "1"]?Set(SL_TARGET=))', $conf);
        self::assertStringContainsString("Set(SL_SENT=1)\n\tsame => n,Gosub(status-lookup-end,s,1)", $conf);
    }

    public function testPinIsCollectedByTheScriptNotByRead(): void
    {
        $conf = self::builder()->contexts();

        // Being asked for a PIN counts as a miss until the PIN is right.
        self::assertStringContainsString("GotoIf(\$[\"\${SL_RESULT}\" != \"need_pin\"]?checked)\n\tsame => n,Set(SL_MISSES=\$[\${SL_MISSES} + 1])\n"
            . "\tsame => n,Set(SL_RESULT=fault)\n\tsame => n,Set(SL_PIN0=)\n\tsame => n,AGI(status_lookup.php,pin,modulestatuslookup-enter-pin,5)", $conf);
        self::assertStringContainsString("same => n(pin_ok),Set(SL_MISSES=\$[\${SL_MISSES} - 1])", $conf);
        // No variable ever holds the typed PIN: only the script sees it.
        self::assertDoesNotMatchRegularExpression('/Set\(SL_PIN=|Read\(SL_PIN/', $conf);
        // Wrong PINs are counted per ID by the script, not by the dialplan.
        self::assertStringNotContainsString('SL_PINID', $conf);
        self::assertStringContainsString('Set(SL_PINFAILS=)', $conf);
        self::assertStringContainsString('same => n(counted),GotoIf($["${SL_RESULT}" == "no_pin" | "${SL_RESULT}" == "no_phone" | "${SL_RESULT}" == "pin_locked"]?operator)', $conf);
    }

    public function testProblems(): void
    {
        self::assertSame([], DialplanBuilder::problems(['welcome' => null, 'enter_id' => 'x'], '2000'));
        self::assertSame(['no_operator', 'prompt_enter_id'], DialplanBuilder::problems(['welcome' => 'x', 'enter_id' => null], ''));
    }
}
