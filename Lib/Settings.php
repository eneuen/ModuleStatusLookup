<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use PDO;

/**
 * Reads and writes the call flow's single settings row (m_StatusLookupSettings,
 * id 1), with defaults for anything missing.
 *
 * Plain PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class Settings
{
    public const string TABLE = 'm_StatusLookupSettings';

    /** Every setting and its default, as stored (text or integer). */
    public const array DEFAULTS = [
        'internal_number'       => '',
        'template'              => '',
        'active'                => '0',
        'welcome_prompt_id'     => -1,
        'enter_id_prompt_id'    => -2,
        'enter_pin_prompt_id'   => -3,
        'invalid_prompt_id'     => -4,
        'not_found_prompt_id'   => -5,
        'repeat_prompt_id'      => -6,
        'goodbye_prompt_id'     => -7,
        'transfer_prompt_id'    => -8,
        'fault_prompt_id'       => -9,
        'id_min_length'         => 4,
        'id_max_length'         => 10,
        'digit_timeout'         => 5,
        'max_tries'             => 3,
        'operator_number'       => '',
        'too_many_tries_number' => '',
        'fault_number'          => '',
        'miss_limit_caller'     => 10,
        'miss_limit_total'      => 200,
        'max_concurrent'        => 10,
        'log_retention_days'    => 0,
        'purge_expired'         => '0',
    ];

    /**
     * The settings, each cast to its default's type. A missing row or column
     * gives the default.
     *
     * @return array<string, int|string>
     */
    public static function load(PDO $db): array
    {
        $row = $db->query('SELECT * FROM ' . self::TABLE . ' WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $settings = [];
        foreach (self::DEFAULTS as $name => $default) {
            $value = is_array($row) ? ($row[$name] ?? null) : null;
            $settings[$name] = match (true) {
                $value === null => $default,
                is_int($default) => (int)$value,
                default => (string)$value,
            };
        }

        return $settings;
    }

    /** Creates the row with its defaults if it doesn't exist yet. */
    public static function ensureRow(PDO $db): void
    {
        $columns = array_keys(self::DEFAULTS);
        $db->prepare('INSERT OR IGNORE INTO ' . self::TABLE . ' (id, ' . implode(', ', $columns) . ') VALUES (1, '
            . implode(', ', array_fill(0, count($columns), '?')) . ')')
            ->execute(array_values(self::DEFAULTS));
    }

    /**
     * Saves some settings. Names must be known; values are written as given
     * (callers validate them).
     *
     * @param array<string, int|string> $values
     */
    public static function save(PDO $db, array $values): void
    {
        if ($values === []) {
            return;
        }
        $unknown = array_diff(array_keys($values), array_keys(self::DEFAULTS));
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown setting: ' . implode(', ', $unknown));
        }
        self::ensureRow($db);
        $assignments = implode(', ', array_map(static fn (string $name): string => "$name = ?", array_keys($values)));
        $db->prepare('UPDATE ' . self::TABLE . " SET $assignments WHERE id = 1")->execute(array_values($values));
    }
}
