<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

/**
 * Checks a change to the call flow (prompts, how callers type, where they
 * go, the miss limits, what is kept and the on/off switch) before it is saved. The
 * internal number is changed separately (the controller, which needs the
 * core), because other things in the PBX may point at it.
 *
 * Every value the page sends is checked against its own rule here; the
 * result holds only known settings, cast to their stored type.
 *
 * Plain PHP, no core dependencies, so it is unit-tested without a PBX.
 */
final class CallFlowEditor
{
    /** Whole-number settings and their allowed range. */
    public const array RANGES = [
        'id_min_length'     => [2, 20],
        'id_max_length'     => [2, 20],
        'digit_timeout'     => [1, 30],
        'max_tries'         => [1, 9],
        'miss_limit_caller' => [0, 1000],
        'miss_limit_total'  => [0, 100000],
        'max_concurrent'    => [0, 500],
        'log_retention_days' => [0, Retention::MAX_DAYS],
    ];

    public const array DESTINATIONS = ['operator_number', 'too_many_tries_number', 'fault_number'];

    /**
     * @param callable(int): bool $promptUsable Whether a prompt ID (built-in or PBX sound file) can be played.
     * @param list<string> $destinations The numbers callers may be sent to (the PBX's destination list).
     */
    public function __construct(private $promptUsable, private readonly array $destinations)
    {
    }

    /**
     * The settings to save for a change. Fields left out keep their
     * current value; switching the line on is refused while anything it
     * needs is missing.
     *
     * @param array<string, mixed> $input What the page sent.
     * @param array<string, int|string> $current Settings::load()
     *
     * @return array<string, int|string> Settings to save.
     *
     * @throws EditorException
     */
    public function change(array $input, array $current): array
    {
        $out = [];
        foreach (DialplanBuilder::PROMPTS as $prompt => $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $id = self::integer($input[$field], $field);
            // Only the welcome may be left out.
            if (!($id === 0 && $prompt === 'welcome') && !($this->promptUsable)($id)) {
                throw new EditorException('prompt', ['field' => $field]);
            }
            $out[$field] = $id;
        }
        foreach (self::RANGES as $field => [$min, $max]) {
            if (array_key_exists($field, $input)) {
                $value = self::integer($input[$field], $field);
                if ($value < $min || $value > $max) {
                    throw new EditorException('range', ['field' => $field, 'min' => $min, 'max' => $max]);
                }
                $out[$field] = $value;
            }
        }
        foreach (self::DESTINATIONS as $field) {
            if (array_key_exists($field, $input)) {
                $number = is_scalar($input[$field]) ? trim((string)$input[$field]) : '';
                if ($number !== '' && !in_array($number, $this->destinations, true)) {
                    throw new EditorException('destination', ['field' => $field]);
                }
                $out[$field] = $number;
            }
        }
        $merged = $out + $current;
        if ((int)$merged['id_min_length'] > (int)$merged['id_max_length']) {
            throw new EditorException('lengths');
        }
        if (array_key_exists('purge_expired', $input)) {
            $out['purge_expired'] = filter_var($input['purge_expired'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }
        if (array_key_exists('active', $input)) {
            $out['active'] = filter_var($input['active'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            $merged['active'] = $out['active'];
        }
        if ($merged['active'] === '1' && ($problems = $this->problems($merged)) !== []) {
            throw new EditorException('not_ready', ['problems' => implode(',', $problems)]);
        }

        return $out;
    }

    /**
     * What stops the line from taking calls: no operator destination, a
     * prompt that can't be played, or no internal number.
     *
     * @param array<string, int|string> $settings
     *
     * @return list<string>
     */
    public function problems(array $settings): array
    {
        $problems = IvrNumber::isValid((string)$settings['internal_number']) ? [] : ['no_number'];
        $operator = (string)$settings['operator_number'];
        if ($operator === '' || !in_array($operator, $this->destinations, true)) {
            $problems[] = 'no_operator';
        }
        foreach (DialplanBuilder::PROMPTS as $prompt => $field) {
            $id = (int)$settings[$field];
            if (!($id === 0 && $prompt === 'welcome') && !($this->promptUsable)($id)) {
                $problems[] = "prompt_$prompt";
            }
        }
        foreach (['too_many_tries_number', 'fault_number'] as $field) {
            $number = (string)$settings[$field];
            if ($number !== '' && !in_array($number, $this->destinations, true)) {
                $problems[] = "gone_$field";
            }
        }

        return $problems;
    }

    private static function integer(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]{1,9}\z/', trim($value)) === 1) {
            return (int)trim($value);
        }
        throw new EditorException('whole_number', ['field' => $field]);
    }
}
