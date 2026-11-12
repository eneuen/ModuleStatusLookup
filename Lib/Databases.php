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

    /** Bytes the write-ahead log is cut back to after a checkpoint. */
    private const int WAL_SIZE_LIMIT = 4 * 1024 * 1024;

    /** Filesystems where SQLite's shared-memory WAL index isn't safe; the same list as the core's. */
    private const array NETWORK_FILESYSTEMS = ['nfs', 'nfs4', 'cifs', 'smb', 'smbfs', 'smb2', 'smb3', 'sshfs', 'davfs', 'glusterfs',
        'ceph', 'lustre', 'gpfs'];

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
        // Write-ahead log: a lookup during a calls' busy hour never waits for an import or the worker to finish
        // writing (with the default rollback journal, a large write locks readers out until it commits). The
        // mode stays with the file; asking again is a no-op. Not on network storage, where WAL isn't safe (as
        // the core decides for its own databases). The log is cut back after a large import.
        if (self::walSafe($file)) {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA journal_size_limit = ' . self::WAL_SIZE_LIMIT);
        }

        return $pdo;
    }

    /**
     * Whether the file is on a local filesystem, by the longest mount point
     * containing it in $mounts (/proc/mounts). Unknown counts as local.
     */
    public static function walSafe(string $file, ?string $mounts = null): bool
    {
        $mounts ??= (string)@file_get_contents('/proc/mounts');
        $best = '';
        $type = '';
        foreach (explode("\n", $mounts) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (!is_array($parts) || count($parts) < 3 || $parts[1] === '') {
                continue;
            }
            $prefix = $parts[1] === '/' ? '/' : $parts[1] . '/';
            if (str_starts_with($file, $prefix) && strlen($parts[1]) > strlen($best)) {
                $best = $parts[1];
                $type = strtolower($parts[2]);
            }
        }

        return !in_array($type, self::NETWORK_FILESYSTEMS, true);
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
