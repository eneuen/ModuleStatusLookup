<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

/**
 * Builds the lookup line's dialplan from its settings.
 *
 * The dialplan does the talking: it plays the prompts, collects the digits
 * with Read() and counts the tries. For each try it runs the lookup AGI
 * script, which only looks the ID up and returns the result in SL_*
 * channel variables (Lookup). So PHP runs for milliseconds per try, and a
 * caller who hangs up leaves no PHP process behind.
 *
 * On entry the line behaves like the core's own IVR menus: dial_app gives
 * it a row in the call history, set-answer-state tells the incoming route
 * the call was answered (so it doesn't go on to the route's next rule or the
 * default action), and its own 2-minute limit replaces the route's ring
 * timeout, which [internal-incoming] puts on the channel. That limit is
 * cleared before the caller is sent on, so a conversation is never cut.
 *
 * Every call is logged once: by a hangup handler when the caller hangs up in
 * the lookup, or just before they are sent on (the core wipes hangup
 * handlers on the way to a phone). The handler runs the hand-off AGI script,
 * which queues the call for the module's worker.
 *
 * Only fixed text, integers, digit strings and sound paths from the module's
 * own list or resolved by the core are written into the dialplan, each
 * checked against a pattern here. Statuses, destinations of statuses and
 * records are read at call time. Every decision point writes an "SLPROBE"
 * line to Asterisk's verbose log, for the call tests and troubleshooting.
 *
 * Plain PHP, no core dependencies, so it is unit-tested without a PBX.
 */
final class DialplanBuilder
{
    public const string CONTEXT = 'status-lookup';
    public const string CONTEXT_END = 'status-lookup-end';

    /** The AGI scripts in agi-bin/, linked into Asterisk's AGI folder by the core. */
    public const string LOOKUP_AGI = 'status_lookup.php';
    public const string END_AGI = 'status_lookup_end.php';

    /** The lookup part of a call ends after this long, whatever happens in it. */
    public const int MAX_SECONDS = 120;

    /** How often a caller can hear a status again with 1. */
    public const int MAX_REPEATS = 3;

    /** The fixed prompts and their settings fields. */
    public const array PROMPTS = [
        'welcome'   => 'welcome_prompt_id',
        'enter_id'  => 'enter_id_prompt_id',
        'invalid'   => 'invalid_prompt_id',
        'not_found' => 'not_found_prompt_id',
        'repeat'    => 'repeat_prompt_id',
        'goodbye'   => 'goodbye_prompt_id',
        'transfer'  => 'transfer_prompt_id',
        'fault'     => 'fault_prompt_id',
    ];

    private const string LANGUAGE_PATTERN = '/^[a-z]{2}-[a-z]{2}\z/';
    private const string BUILT_IN_PATTERN = '/^modulestatuslookup-[a-z-]{1,20}\z/';

    /** Values every probe log line carries. */
    private const string COMMON = 'linkedid=${CHANNEL(linkedid)} chan=${CHANNEL}';

    private string $number;

    private bool $active;

    /** @var array<string, ?string> Prompt => what to Playback, or null when unusable. */
    private array $prompts = [];

    private string $operator;
    private string $tooMany;
    private string $fault;
    private int $minLength;
    private int $maxLength;
    private int $timeout;
    private int $maxTries;
    private string $language;

    /**
     * @param array<string, int|string> $settings Settings::load()
     * @param string $language The PBX's language, such as en-en.
     * @param string $soundsDir The PBX's Asterisk sounds folder (for built-in prompts in other languages).
     * @param callable(int): ?string $promptPath A PBX sound file ID => path for Playback, or null.
     */
    public function __construct(array $settings, string $language, string $soundsDir, callable $promptPath)
    {
        $number = (string)$settings['internal_number'];
        $this->number = IvrNumber::isValid($number) ? $number : '';
        $language = strtolower(str_replace('_', '-', $language));
        $this->language = preg_match(self::LANGUAGE_PATTERN, $language) === 1 ? $language : BuiltInPrompts::LANGUAGES[0];

        foreach (self::PROMPTS as $prompt => $field) {
            $this->prompts[$prompt] = self::resolve((int)$settings[$field], $this->language, $soundsDir, $promptPath);
        }
        $this->operator = self::destination((string)$settings['operator_number']);
        $this->tooMany = self::destination((string)$settings['too_many_tries_number']) ?: $this->operator;
        $this->fault = self::destination((string)$settings['fault_number']) ?: $this->operator;
        $this->minLength = max(2, min(20, (int)$settings['id_min_length']));
        $this->maxLength = max($this->minLength, min(20, (int)$settings['id_max_length']));
        $this->timeout = max(1, min(30, (int)$settings['digit_timeout']));
        $this->maxTries = max(1, min(9, (int)$settings['max_tries']));
        $this->active = (string)$settings['active'] === '1' && self::problems($this->prompts, $this->operator) === [];
    }

    /**
     * Why the line can't take calls yet; empty when it can.
     *
     * @param array<string, ?string> $prompts
     *
     * @return list<string> Problem codes: no_operator, prompt_<name>.
     */
    public static function problems(array $prompts, string $operator): array
    {
        $problems = $operator === '' ? ['no_operator'] : [];
        foreach ($prompts as $prompt => $path) {
            if ($path === null && $prompt !== 'welcome') {
                $problems[] = "prompt_$prompt";
            }
        }

        return $problems;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** The line's number in [internal]: reached by routes, IVR menus and phones. */
    public function internal(): string
    {
        return $this->number === '' ? '' : "exten => $this->number,1,Goto(" . self::CONTEXT . ",s,1)\n";
    }

    /** The line's number in [internal-transfer], so phones can transfer callers to it. Same as the core's IVR menus. */
    public function internalTransfer(): string
    {
        if ($this->number === '') {
            return '';
        }

        return "exten => $this->number,1,Set(__ISTRANSFER=transfer_)\n"
            . "\tsame => n,Set(__QUEUE_SRC_CHAN=\${EMPTY})\n"
            . "\tsame => n,Goto(internal,$this->number,1)\n";
    }

    /** The module's own contexts. Neither is included in a routable context. */
    public function contexts(): string
    {
        if ($this->number === '') {
            return '';
        }

        return "\n[" . self::CONTEXT . "]\n" . self::priorities('s', [...$this->entry(), ...($this->active ? $this->flow() : $this->inactive())])
            . "\n[" . self::CONTEXT_END . "]\n" . self::priorities('s', $this->end());
    }

    /** @return list<string|array{string, string}> */
    private function entry(): array
    {
        return [
            'Set(SL_V=' . Handoff::VERSION . ')',
            'Set(SL_STARTED=${EPOCH})',
            'Set(SL_CALLER=${FILTER(0-9,${CALLERID(num)})})',
            'Set(SL_OUTCOME=abandoned)',
            'Set(SL_TRIES=0)',
            'Set(SL_MISSES=0)',
            'Set(SL_REPEATS=0)',
            'Set(CHANNEL(hangup_handler_push)=' . self::CONTEXT_END . ',s,1)',
            // As the core's IVR menus: a row in the call history. No recording: callers type IDs and PINs here.
            'ExecIf($["${CHANNEL(channeltype)}" == "Local"]?Gosub(set_orign_chan,s,1))',
            "Set(APPEXTEN=$this->number)",
            'Gosub(dial_app,${EXTEN},1)',
            // The route's Dial treats the call as answered, so it doesn't go on to its next rule.
            'Gosub(set-answer-state,${EXTEN},1)',
            'Answer()',
            'Set(TIMEOUT(absolute)=' . self::MAX_SECONDS . ')',
            "Set(CHANNEL(language)=$this->language)",
            'Verbose(3,SLPROBE event=entry active=' . ($this->active ? '1' : '0') . ' ' . self::COMMON . ')',
        ];
    }

    /**
     * Not active yet (or misconfigured): an apology, then hang up.
     *
     * @return list<string|array{string, string}>
     */
    private function inactive(): array
    {
        return [
            'Set(SL_OUTCOME=inactive)',
            $this->prompts['fault'] !== null ? 'Playback(' . $this->prompts['fault'] . ')' : 'Playback(beeperr)',
            'Hangup()',
        ];
    }

    /** @return list<string|array{string, string}> */
    private function flow(): array
    {
        $c = self::COMMON;
        $p = $this->prompts;
        $agi = self::LOOKUP_AGI;
        $exists = static fn (string $var): string => '${DIALPLAN_EXISTS(internal,${' . $var . '},1)}';

        // Prompts the caller may answer are played by Read(), so a key cuts them short: the
        // welcome and "not found" come before the ID prompt in the same Read().
        $enter = $p['enter_id'];
        $first = $p['welcome'] !== null ? "{$p['welcome']}&$enter" : $enter;

        return [
            "Set(SL_ASK=$first)",
            ['ask', "GotoIf(\$[\${SL_TRIES} >= $this->maxTries]?too_many)"],
            'Set(SL_TRIES=$[${SL_TRIES} + 1])',
            "Read(SL_IN,\${SL_ASK},$this->maxLength,,1,$this->timeout)",
            "Verbose(3,SLPROBE event=input in=\${FILTER(0-9*#,\${SL_IN})} read=\${READSTATUS} try=\${SL_TRIES} $c)",
            'GotoIf($["${SL_IN}" == "0"]?operator)',
            "AGI($agi,lookup)",
            "Verbose(3,SLPROBE event=lookup result=\${SL_RESULT} id=\${SL_ID} status=\${SL_STATUS} $c)",
            'GotoIf($["${SL_RESULT}" == "hit"]?status)',
            'GotoIf($["${SL_RESULT}" == "rate_limited"]?rate_limited)',
            'GotoIf($["${SL_RESULT}" == "fault"]?fault)',
            'Set(SL_MISSES=$[${SL_MISSES} + 1])',
            "Set(SL_MISSED=\${IF(\$[\"\${SL_RESULT}\" == \"invalid\"]?{$p['invalid']}:{$p['not_found']})})",
            "GotoIf(\$[\${SL_TRIES} >= $this->maxTries]?last_miss)",
            "Set(SL_ASK=\${SL_MISSED}&$enter)",
            'Goto(ask)',
            ['last_miss', 'Playback(${SL_MISSED})'],
            'Goto(too_many)',

            ['status', 'Set(SL_OUTCOME=hit)'],
            // The status sentence: 0 during it reaches a person.
            ['say', 'Read(SL_KEY,${SL_PROMPT},1,,1,0.5)'],
            'GotoIf($["${SL_KEY}" == "0"]?operator)',
            'ExecIf($["${SL_EXTRA}" == "id"]?SayDigits(${SL_ID}))',
            "ExecIf(\$[\"\${SL_EXTRA}\" == \"date\"]?AGI($agi,date))",
            'GotoIf($["${SL_ACTION}" == "transfer"]?transfer)',
            'GotoIf($["${SL_ACTION}" != "repeat"]?goodbye)',
            'Set(SL_REPEATS=$[${SL_REPEATS} + 1])',
            'GotoIf($[${SL_REPEATS} > ' . self::MAX_REPEATS . ']?goodbye)',
            "Read(SL_KEY,{$p['repeat']},1,,1,$this->timeout)",
            "Verbose(3,SLPROBE event=menu key=\${FILTER(0-9*#,\${SL_KEY})} $c)",
            'GotoIf($["${SL_KEY}" == "1"]?say)',
            'GotoIf($["${SL_KEY}" == "0"]?operator)',
            ['goodbye', "Playback({$p['goodbye']})"],
            "Verbose(3,SLPROBE event=goodbye $c)",
            'Hangup()',

            ['transfer', 'Set(SL_TARGET=${FILTER(0-9,${SL_TRANSFER})})'],
            'Goto(leave)',
            ['operator', 'ExecIf($["${SL_OUTCOME}" != "hit"]?Set(SL_OUTCOME=operator))'],
            "Set(SL_TARGET=$this->operator)",
            'Goto(leave)',
            ['too_many', 'Set(SL_OUTCOME=too_many)'],
            "Set(SL_TARGET=$this->tooMany)",
            'Goto(leave)',
            ['rate_limited', 'Set(SL_OUTCOME=rate_limited)'],
            "Set(SL_TARGET=$this->operator)",
            'Goto(leave)',
            ['fault', 'Set(SL_OUTCOME=fault)'],
            "Set(SL_TARGET=$this->fault)",

            // A destination that no longer exists sends the caller to the fault destination; if that is gone too, an apology.
            ['leave', 'GotoIf($["${SL_TARGET}x" != "x" & ' . $exists('SL_TARGET') . ']?go)'],
            "Verbose(3,SLPROBE event=missing_target target=\${SL_TARGET} $c)",
            'Set(SL_OUTCOME=fault)',
            "Set(SL_TARGET=$this->fault)",
            'GotoIf(' . $exists('SL_TARGET') . '?go)',
            'Set(SL_TARGET=)',
            "Playback({$p['fault']})",
            'Hangup()',
            ['go', "ExecIf(\$[\"\${SL_OUTCOME}\" == \"fault\"]?Playback({$p['fault']}))"],
            "Playback({$p['transfer']})",
            "Verbose(3,SLPROBE event=leave outcome=\${SL_OUTCOME} target=\${SL_TARGET} $c)",
            // Logged now: the core wipes hangup handlers on the way to a phone.
            'Set(SL_SENT=1)',
            'Gosub(' . self::CONTEXT_END . ',s,1)',
            'Set(TIMEOUT(absolute)=0)',
            'Goto(internal,${SL_TARGET},1)',
        ];
    }

    /**
     * The hand-off: once per call, when the caller hangs up in the lookup or
     * just before they are sent on.
     *
     * @return list<string|array{string, string}>
     */
    private function end(): array
    {
        return [
            'GotoIf($["${SL_LOGGED}" == "1"]?done)',
            'Set(SL_LOGGED=1)',
            // A caller who hangs up before being handed over reached nobody.
            'ExecIf($["${SL_SENT}" != "1"]?Set(SL_TARGET=))',
            'ExecIf($["${SL_LEFT}x" == "x"]?Set(SL_LEFT=${EPOCH}))',
            'Verbose(3,SLPROBE event=end outcome=${SL_OUTCOME} result=${SL_RESULT} tries=${SL_TRIES} misses=${SL_MISSES} '
                . 'status=${SL_STATUS} target=${SL_TARGET} ' . self::COMMON . ')',
            'AGI(' . self::END_AGI . ')',
            ['done', 'Return()'],
        ];
    }

    /**
     * A prompt ID as what to Playback, or null when it can't be played.
     *
     * @param callable(int): ?string $promptPath
     */
    public static function resolve(int $id, string $language, string $soundsDir, callable $promptPath): ?string
    {
        if (BuiltInPrompts::isBuiltIn($id)) {
            $name = BuiltInPrompts::playback($id, $language, $soundsDir);

            return $name !== null && (preg_match(self::BUILT_IN_PATTERN, $name) === 1 || preg_match(Lookup::PATH_PATTERN, $name) === 1)
                ? $name : null;
        }
        if ($id <= 0) {
            return null;
        }
        $path = $promptPath($id);

        return $path !== null && preg_match(Lookup::PATH_PATTERN, $path) === 1 ? $path : null;
    }

    private static function destination(string $number): string
    {
        return preg_match(Lookup::NUMBER_PATTERN, $number) === 1 ? $number : '';
    }

    /**
     * One extension's priorities. A line is an application string, or
     * [label, application] for a labelled priority.
     *
     * @param list<string|array{string, string}> $lines
     */
    private static function priorities(string $exten, array $lines): string
    {
        $out = '';
        foreach ($lines as $i => $line) {
            [$label, $app] = is_array($line) ? $line : [null, $line];
            $priority = $i === 0 ? '1' : 'n';
            $prefix = $i === 0 ? "exten => $exten," : "\tsame => ";
            $out .= $prefix . $priority . ($label !== null ? "($label)" : '') . ',' . $app . "\n";
        }

        return $out;
    }
}
