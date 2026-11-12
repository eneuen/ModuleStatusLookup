<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

/**
 * The wrong PINs of one call, per ID, as the PIN script keeps them in the
 * channel variable SL_PINFAILS until the call is logged: "10445:2;20001:1;".
 * Counting per ID matters: a caller who mixes IDs must not move one ID's
 * wrong PINs onto another.
 *
 * Plain PHP, no core dependencies, so it is unit-tested without a PBX.
 */
final class PinFails
{
    /** One entry per ID: at most one ID per try, and at most 9 tries. */
    public const string PATTERN = '/^([0-9]{1,20}:[0-9]{1,2};){0,9}\z/';

    /** @return array<string, int> ID => wrong PINs; empty for anything malformed. */
    public static function parse(string $list): array
    {
        if (preg_match(self::PATTERN, $list) !== 1) {
            return [];
        }
        $out = [];
        foreach (array_filter(explode(';', $list)) as $entry) {
            [$id, $n] = explode(':', $entry);
            $out[$id] = (int)$n;
        }

        return $out;
    }

    /** @param array<string, int> $fails */
    public static function format(array $fails): string
    {
        $list = '';
        foreach ($fails as $id => $n) {
            $list .= $id . ':' . min(99, max(0, $n)) . ';';
        }

        return $list;
    }

    /** The list with one more wrong PIN for $id. */
    public static function add(string $list, string $id): string
    {
        $fails = self::parse($list);
        $fails[$id] = ($fails[$id] ?? 0) + 1;

        return self::format($fails);
    }
}
