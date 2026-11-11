<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Tests\Unit\Support;

use PDO;

/**
 * In-memory databases for the tests: the module's own, built from the model
 * annotations (as the core builds it on install), and a minimal copy of the
 * core's configuration database.
 */
final class TestDatabases
{
    private const string MODELS = __DIR__ . '/../../../Models';

    public static function module(): PDO
    {
        $pdo = self::memory();
        foreach (glob(self::MODELS . '/*.php') ?: [] as $model) {
            foreach (self::tableSql((string)file_get_contents($model)) as $sql) {
                $pdo->exec($sql);
            }
        }

        return $pdo;
    }

    /** The core tables the module reads, as a fresh PBX has them. */
    public static function core(): PDO
    {
        $pdo = self::memory();
        $pdo->exec('CREATE TABLE m_Extensions (id INTEGER PRIMARY KEY AUTOINCREMENT, number VARCHAR, type VARCHAR, callerid VARCHAR,
            userid INTEGER, show_in_phonebook VARCHAR, public_access VARCHAR, is_general_user_number VARCHAR)');
        $pdo->exec('CREATE TABLE m_PbxSettings (key VARCHAR PRIMARY KEY, value VARCHAR)');
        $pdo->exec('CREATE TABLE m_SoundFiles (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR, path VARCHAR, category VARCHAR)');

        return $pdo;
    }

    private static function memory(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * CREATE TABLE and CREATE INDEX statements for one model file, read from
     * its setSource(), @Column, @Identity and @Indexes annotations.
     *
     * @return list<string>
     */
    private static function tableSql(string $source): array
    {
        preg_match("/setSource\\('([^']+)'\\)/", $source, $m);
        $table = $m[1];

        preg_match_all('/\/\*\*((?:(?!\*\/).)*?)\*\/\s*public \$(\w+)(?:\s*=[^;]*)?;/s', $source, $props, PREG_SET_ORDER);
        $columns = [];
        foreach ($props as [, $doc, $name]) {
            if (!preg_match('/@Column\(type="(\w+)"/', $doc, $type)) {
                continue;
            }
            if (str_contains($doc, '@Identity')) {
                $columns[] = "`$name` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL";
                continue;
            }
            $sqlType = $type[1] === 'integer' ? 'INTEGER' : 'VARCHAR';
            $null = str_contains($doc, 'nullable=false') ? 'NOT NULL' : 'NULL';
            $default = preg_match('/default="([^"]*)"/', $doc, $d) ? " DEFAULT '{$d[1]}'" : '';
            $columns[] = "`$name` $sqlType $null$default";
        }
        $sql = ["CREATE TABLE `$table` (\n  " . implode(",\n  ", $columns) . "\n)"];

        preg_match_all("/\\[name='(\\w+)', columns=\\[([^\\]]+)\\], type='(\\w*)'\\]/", $source, $indexes, PREG_SET_ORDER);
        foreach ($indexes as [, $name, $cols, $type]) {
            $cols = implode(',', array_map(fn (string $c): string => '`' . trim($c, " '") . '`', explode(',', $cols)));
            $unique = strtoupper($type) === 'UNIQUE' ? 'UNIQUE ' : '';
            $sql[] = "CREATE {$unique}INDEX `i_{$table}_$name` ON `$table` ($cols)";
        }

        return $sql;
    }
}
