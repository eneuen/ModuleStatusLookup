<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use InvalidArgumentException;

/**
 * The end-of-call record the hand-off AGI script queues for the worker.
 *
 * The script builds it from the call's SL_* channel variables; the worker
 * validates it again when it arrives, because anything that can publish to
 * the queue reaches it. Every field must match a fixed pattern, so nothing
 * outside the module's own value set reaches the database.
 *
 * Plain PHP, no core dependencies, so it is unit-tested without a PBX.
 */
final class Handoff
{
    public const int VERSION = 1;

    public const array OUTCOMES = ['hit', 'operator', 'too_many', 'rate_limited', 'busy', 'fault', 'inactive', 'abandoned'];

    /** Channel variable => record field, with the pattern the value must match. */
    private const array FIELDS = [
        'SL_V'       => ['v', '/^[0-9]{1,3}\z/'],
        'SL_STARTED' => ['started_at', '/^[0-9]{1,12}\z/'],
        'SL_LEFT'    => ['ended_at', '/^[0-9]{0,12}\z/'],
        'SL_CALLER'  => ['caller', '/^[0-9]{0,32}\z/'],
        'SL_TRIES'   => ['tries', '/^[0-9]{1,2}\z/'],
        'SL_MISSES'  => ['misses', '/^[0-9]{1,2}\z/'],
        'SL_OUTCOME' => ['outcome', '/^(hit|operator|too_many|rate_limited|busy|fault|inactive|abandoned)\z/'],
        'SL_RESULT'  => ['last_result', '/^(|hit|invalid|not_found|expired|rate_limited|fault|need_pin|no_pin|no_phone|pin_failed|pin_locked|callerid_failed)\z/'],
        'SL_ID'      => ['id_entered', '/^[0-9]{0,20}\z/'],
        'SL_STATUS'  => ['status_code', '/^[A-Z0-9_]{0,32}\z/'],
        'SL_TARGET'  => ['target', '/^[0-9]{0,8}\z/'],
        'SL_TEST'    => ['is_test', '/^1?\z/'],
    ];

    private const string LINKEDID_PATTERN = '/^[A-Za-z0-9._-]{1,80}\z/';

    /**
     * The channel variables the script reads, besides the linkedid.
     *
     * @return list<string>
     */
    public static function channelVariables(): array
    {
        return array_keys(self::FIELDS);
    }

    /**
     * Builds a record from channel variables.
     *
     * @param array<string, string> $vars Channel variable => value; a missing one counts as empty.
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException When any value is outside its pattern.
     */
    public static function fromChannel(string $linkedId, array $vars, int $now): array
    {
        $raw = ['linkedid' => $linkedId];
        foreach (self::FIELDS as $var => [$field]) {
            $raw[$field] = (string)($vars[$var] ?? '');
        }
        // The caller hung up before the lookup recorded when they left.
        if ($raw['ended_at'] === '') {
            $raw['ended_at'] = (string)$now;
        }
        // Withheld or odd caller IDs: digits only (the dialplan already filters them).
        $raw['caller'] = Lookup::digits($raw['caller']);

        return self::validate($raw);
    }

    public static function encode(array $record): string
    {
        return json_encode($record, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     *
     * @throws InvalidArgumentException When the message is not a valid record.
     */
    public static function decode(string $message): array
    {
        try {
            $record = json_decode($message, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Not JSON');
        }
        if (!is_array($record)) {
            throw new InvalidArgumentException('Not a record');
        }

        return self::validate($record);
    }

    /**
     * @param array<mixed> $record
     *
     * @return array<string, string>
     */
    private static function validate(array $record): array
    {
        $out = [];
        if (!is_string($record['linkedid'] ?? null) || preg_match(self::LINKEDID_PATTERN, $record['linkedid']) !== 1) {
            throw new InvalidArgumentException('Invalid linkedid');
        }
        $out['linkedid'] = $record['linkedid'];
        foreach (self::FIELDS as [$field, $pattern]) {
            $value = $record[$field] ?? null;
            if (!is_string($value) || preg_match($pattern, $value) !== 1) {
                throw new InvalidArgumentException("Invalid $field");
            }
            $out[$field] = $value;
        }
        if ((int)$out['v'] !== self::VERSION) {
            throw new InvalidArgumentException('Unknown record version');
        }
        if ((int)$out['ended_at'] < (int)$out['started_at']) {
            $out['ended_at'] = $out['started_at'];
        }

        return $out;
    }
}
