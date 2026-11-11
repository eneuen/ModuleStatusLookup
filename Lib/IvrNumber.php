<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

/**
 * The rules for the lookup IVR's internal number.
 *
 * The default is 2300, then the first free number up to 2399. The core
 * numbers its own IVR menus, queues and applications from 2200100 up, so a
 * number such as 2200 would be a prefix of them, and dialling it from inside
 * an IVR menu would wait for the inter-digit timeout. Other modules (On Call
 * Calendar) hand out numbers from 2200 up.
 *
 * Pure PHP, no core dependencies, so it is unit-tested without a PBX.
 */
final class IvrNumber
{
    public const string DEFAULT = '2300';

    public const int RANGE_LAST = 2399;

    /** Digits only, 2 to 8 of them, not starting with 0 (the core's own numbers never do). */
    public const string PATTERN = '/^[1-9][0-9]{1,7}\z/';

    public static function isValid(string $number): bool
    {
        return preg_match(self::PATTERN, $number) === 1;
    }

    /**
     * The default number, or the first free one after it within the range.
     *
     * @param list<string> $taken Every number already in the core's extensions table.
     *
     * @return string|null Null when the whole range is taken.
     */
    public static function firstFree(array $taken): ?string
    {
        $range = array_map('strval', range((int)self::DEFAULT, self::RANGE_LAST));
        $free = array_values(array_diff($range, $taken));

        return $free[0] ?? null;
    }

    /**
     * The number for a new install: the module's own number left over from an
     * earlier install (its uninstall could not release it because a route
     * still pointed at it), so those routes work again; otherwise the default
     * or the first free one after it.
     *
     * @param list<string> $taken Every number already in the core's extensions table.
     * @param list<string> $leftovers Numbers of MODULES extensions named as this module names its number.
     */
    public static function forNewInstall(array $taken, array $leftovers): ?string
    {
        $own = array_values(array_filter($leftovers, [self::class, 'isValid']));
        sort($own, SORT_STRING);

        return $own[0] ?? self::firstFree($taken);
    }

    /**
     * The name the core's pickers show, as "Module: <callerid>". They show no
     * number, so the name carries it.
     */
    public static function callerId(string $label, string $number): string
    {
        return "$label ($number)";
    }
}
