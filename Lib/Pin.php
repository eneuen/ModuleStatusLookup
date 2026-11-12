<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use RuntimeException;

/**
 * Record PINs: 4 to 8 digits, stored only as a keyed hash (HMAC-SHA256).
 *
 * The key is a random secret in its own file next to the database
 * (db/pin.key, readable by its owner only), created on first use. A short PIN can
 * be tried exhaustively from any hash, so a slow hash such as bcrypt would
 * buy little (and make a large import take hours); without the key, the
 * stored hashes are useless. The core keeps the whole db/ folder when a
 * module is reinstalled with its settings, so the key survives that; an
 * uninstall without kept settings removes both.
 *
 * Plain PHP, no core dependencies, so it is unit-tested without a PBX.
 */
final class Pin
{
    public const string PATTERN = '/^[0-9]{4,8}\z/';

    /** Stored hashes start with this, so the scheme can change later. */
    private const string PREFIX = 'h1:';

    public function __construct(private readonly string $key)
    {
        if (strlen($key) < 32) {
            throw new RuntimeException('PIN key too short');
        }
    }

    /** A key file holds 64 hex digits (32 random bytes), nothing else. */
    private const string KEY_PATTERN = '/^[0-9a-f]{64}\z/';

    /**
     * The key in $file, created if there is none yet.
     *
     * A new key is written to a temporary file in the same folder and only
     * then linked into place, so no process ever reads a half-written key,
     * and when two create one at once, the first link wins and both use it.
     * The folder belongs to the web server's user (www), while calls and the
     * REST API run as root, so root never writes, reads or changes the mode
     * of anything through a link here: the temporary file is created
     * exclusively (a link in its place makes that fail) and written through
     * its handle, and a key that is a link, or anything but a single regular
     * file, is refused. (Anyone who can write the folder can also change the
     * module's code, which root runs; this only keeps root's own steps safe.)
     *
     * @throws RuntimeException When there is no usable key.
     */
    public static function fromFile(string $file): self
    {
        if (!file_exists($file) && !is_link($file)) {
            self::create($file);
        }
        $key = trim(self::read($file));
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new RuntimeException('PIN key is unreadable or damaged');
        }
        self::secure($file);

        return new self($key);
    }

    /**
     * Makes the key owner-only. Root runs this only where nothing else is
     * running against the module (the install); otherwise the owner (www)
     * does it on its next read.
     */
    public static function lockDown(string $file): void
    {
        $stat = @lstat($file);
        if ($stat !== false && ($stat['mode'] & 0170000) === 0100000 && ($stat['mode'] & 0077) !== 0) {
            @chmod($file, 0600);
        }
    }

    private static function create(string $file): void
    {
        $dir = dirname($file);
        $temp = $dir . '/.pin-' . bin2hex(random_bytes(8));
        $mask = umask(0077);   // created 0600, without a chmod by name
        try {
            $handle = @fopen($temp, 'x');   // O_EXCL: fails if anything, a link included, is already there
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            throw new RuntimeException('PIN key can\'t be created');
        }
        try {
            $written = fwrite($handle, bin2hex(random_bytes(32)));
            fclose($handle);
            if ($written !== 64) {
                throw new RuntimeException('PIN key can\'t be written');
            }
            self::handOver($temp);
            // Fails if another process linked its key first; that one is used. link() doesn't follow a link.
            @link($temp, $file);
        } finally {
            @unlink($temp);
        }
    }

    /** The key file's text, read only if it is one regular file (not a link, not a device or pipe, not hard-linked elsewhere). */
    private static function read(string $file): string
    {
        $before = @lstat($file);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1) {
            throw new RuntimeException('PIN key is not a regular file');
        }
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            throw new RuntimeException('PIN key is unreadable or damaged');
        }
        try {
            $opened = fstat($handle);
            // The file opened must be the one checked: nothing swapped in between.
            if ($opened === false || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']) {
                throw new RuntimeException('PIN key changed while being read');
            }
            return (string)fread($handle, 128);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Keeps the key owner-only and owned by the web server's user. The core
     * resets module files to 0644 when it installs a module: the owner (www)
     * tightens the mode on its next read; root only hands the file over.
     */
    private static function secure(string $file): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::handOver($file);
            return;
        }
        self::lockDown($file);
    }

    /** As root: gives the file to the owner of its folder (www), without following links. */
    private static function handOver(string $file): void
    {
        if (!function_exists('posix_getuid') || posix_getuid() !== 0) {
            return;
        }
        $owner = @fileowner(dirname($file));
        $group = @filegroup(dirname($file));
        $stat = @lstat($file);
        if ($stat === false) {
            return;
        }
        if ($owner !== false && $stat['uid'] !== $owner) {
            @lchown($file, $owner);
        }
        if ($group !== false && $stat['gid'] !== $group) {
            @lchgrp($file, $group);
        }
    }

    public static function isValid(string $pin): bool
    {
        return preg_match(self::PATTERN, $pin) === 1;
    }

    public function hash(string $pin): string
    {
        return self::PREFIX . hash_hmac('sha256', $pin, $this->key);
    }

    /** Constant-time check of a typed PIN against a stored hash; an empty hash never matches. */
    public function matches(string $pin, string $stored): bool
    {
        return $stored !== '' && self::isValid($pin) && hash_equals($stored, $this->hash($pin));
    }
}
