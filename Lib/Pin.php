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
     * REST API run as root, so root never follows a link here: a key that is
     * a link, or anything but a regular file, is refused.
     *
     * @throws RuntimeException When there is no usable key.
     */
    public static function fromFile(string $file): self
    {
        if (!file_exists($file) && !is_link($file)) {
            self::create($file);
        }
        if (is_link($file) || !is_file($file)) {
            throw new RuntimeException('PIN key is not a regular file');
        }
        self::secure($file);
        $key = trim((string)@file_get_contents($file));
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new RuntimeException('PIN key is unreadable or damaged');
        }

        return new self($key);
    }

    private static function create(string $file): void
    {
        $dir = dirname($file);
        $temp = @tempnam($dir, '.pin-');   // created 0600, in the same folder, so link() works
        // tempnam() falls back to the system's temp folder when it can't write here; link() would then fail anyway.
        if ($temp === false || realpath(dirname($temp)) !== realpath($dir)) {
            if ($temp !== false) {
                @unlink($temp);
            }
            throw new RuntimeException('PIN key can\'t be created');
        }
        try {
            if (@file_put_contents($temp, bin2hex(random_bytes(32))) !== 64) {
                throw new RuntimeException('PIN key can\'t be written');
            }
            self::handOver($temp);
            // Fails if another process linked its key first; that one is used.
            @link($temp, $file);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Keeps the key readable by its owner only. The core resets module files
     * to 0644 when it installs a module, so a read by the owner (www)
     * tightens the mode again; root does so only for a regular file.
     */
    private static function secure(string $file): void
    {
        $perms = @fileperms($file);
        if ($perms !== false && ($perms & 0077) !== 0 && !is_link($file)) {
            @chmod($file, 0600);
        }
        self::handOver($file);
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
