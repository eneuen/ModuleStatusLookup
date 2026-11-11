<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use MikoPBX\Common\Models\Extensions;
use RuntimeException;

/**
 * Keeps the IVR's number registered in the core's extensions table as a
 * MODULES extension. That reserves the number and, together with
 * StatusLookupSettings::getDynamicRelations(), makes it a choice in the
 * core's destination pickers. Same approach as On Call Calendar's schedule
 * numbers.
 */
final class CoreNumber
{
    /** The name the pickers show before the number. */
    public const string LABEL = 'Status Lookup';

    /**
     * The numbers already in the core's extensions table.
     *
     * @return list<string>
     */
    public static function taken(): array
    {
        return array_map('strval', array_column(Extensions::find(['columns' => 'number'])->toArray(), 'number'));
    }

    /**
     * MODULES extensions named exactly as this module names its number,
     * "Status Lookup (<number>)": numbers an earlier install left behind.
     *
     * @return list<string>
     */
    public static function leftovers(): array
    {
        $numbers = [];
        $rows = Extensions::find([
            'type = :type:',
            'bind'    => ['type' => Extensions::TYPE_MODULES],
            'columns' => 'number, callerid',
        ])->toArray();
        foreach ($rows as $row) {
            $number = (string)$row['number'];
            if ((string)$row['callerid'] === IvrNumber::callerId(self::LABEL, $number)) {
                $numbers[] = $number;
            }
        }

        return $numbers;
    }

    /**
     * Creates or updates the extension for the IVR's number.
     *
     * @throws RuntimeException When another extension already uses it, or it can't be saved.
     */
    public static function register(string $number): void
    {
        if (!IvrNumber::isValid($number)) {
            throw new RuntimeException("Not a valid internal number: $number");
        }
        $extension = Extensions::findFirstByNumber($number);
        if ($extension !== null && !self::isOwn($extension->type, $extension->callerid, $number)) {
            throw new RuntimeException("The number $number is already used by another extension.");
        }
        $extension ??= new Extensions();
        $extension->number = $number;
        $extension->type = Extensions::TYPE_MODULES;
        $extension->callerid = IvrNumber::callerId(self::LABEL, $number);
        $extension->userid = null;
        $extension->show_in_phonebook = '1';
        $extension->public_access = '1';
        $extension->is_general_user_number = '0';
        if (!$extension->save()) {
            throw new RuntimeException('The number could not be saved: ' . implode(' ', array_map('strval', $extension->getMessages())));
        }
    }

    /**
     * Whether the core's extensions table holds this number as the module's
     * own: a MODULES extension named as the module names it. The hooks write
     * the line's dialplan only then, so a number another extension or module
     * has taken never gets a second entry in [internal].
     */
    public static function isRegistered(string $number): bool
    {
        $extension = Extensions::findFirstByNumber($number);

        return $extension !== null && self::isOwn($extension->type, $extension->callerid, $number);
    }

    private static function isOwn(?string $type, ?string $callerId, string $number): bool
    {
        return $type === Extensions::TYPE_MODULES && $callerId === IvrNumber::callerId(self::LABEL, $number);
    }

    /**
     * Removes the extension for a number the IVR no longer uses. The core
     * refuses while an incoming route or another rule still points at it, and
     * returns its reasons.
     *
     * @return list<string> Why it could not be removed; empty on success.
     */
    public static function unregister(string $number): array
    {
        $extension = Extensions::findFirst([
            'number = :number: AND type = :type:',
            'bind' => ['number' => $number, 'type' => Extensions::TYPE_MODULES],
        ]);
        if ($extension === null || $extension->delete()) {
            return [];
        }

        return array_map('strval', $extension->getMessages());
    }
}
