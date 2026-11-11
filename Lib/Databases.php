<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use InvalidArgumentException;
use PDO;
use Pdo\Sqlite;

/**
 * Opens the SQLite databases the module uses with plain PDO: its own
 * database (read and write) and the core's configuration database (read
 * only). Plain PDO keeps the logic testable without Phalcon and avoids model
 * events: the module decides itself when the dialplan must be reloaded.
 */
final class Databases
{
    private const int BUSY_TIMEOUT_MS = 5000;

    public static function openReadOnly(string $file): PDO
    {
        if (!is_file($file)) {
            throw new InvalidArgumentException("Database file not found: $file");
        }
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            Sqlite::ATTR_OPEN_FLAGS      => Sqlite::OPEN_READONLY,
        ]);
        $pdo->exec('PRAGMA query_only = 1');
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);

        return $pdo;
    }

    public static function openWritable(string $file): PDO
    {
        if (!is_file($file)) {
            throw new InvalidArgumentException("Database file not found: $file");
        }
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        $pdo->exec('PRAGMA foreign_keys = OFF');

        return $pdo;
    }

    /**
     * Runs $work in a write transaction that takes the write lock at its
     * start (BEGIN IMMEDIATE). A deferred transaction that reads first and
     * then writes can fail at once with "database is locked" when another
     * process wrote in between; an immediate one waits for the lock (busy
     * timeout) instead. The page, the worker and imports all write the
     * module's database.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function immediate(PDO $db, callable $work): mixed
    {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $result = $work();
            $db->exec('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }

    /** A value from the core's m_PbxSettings, or $default when it is not set. */
    public static function coreSetting(PDO $core, string $key, string $default = ''): string
    {
        $stmt = $core->prepare('SELECT value FROM m_PbxSettings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null || $value === '' ? $default : (string)$value;
    }
}
