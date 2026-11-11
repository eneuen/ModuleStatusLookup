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

    /** The key in $file, created (random, root-only) if there is none yet. */
    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            // 'x' fails if another process created it first; then that one is read.
            $handle = @fopen($file, 'x');
            if ($handle !== false) {
                chmod($file, 0600);
                fwrite($handle, bin2hex(random_bytes(32)));
                fclose($handle);
            }
        }
        if (is_file($file)) {
            self::secure($file);
        }
        $key = trim((string)@file_get_contents($file));
        if ($key === '') {
            throw new RuntimeException('PIN key not readable');
        }

        return new self($key);
    }

    /**
     * Keeps the key readable by its owner only. The core resets module files
     * to 0644 when it installs a module, and the key is first written by
     * whichever runs first: the web server (user www) or a call (root). So
     * every read tightens the mode again, and root hands the file to the
     * owner of its folder (www), so the admin page can still read it.
     */
    private static function secure(string $file): void
    {
        $perms = @fileperms($file);
        if ($perms !== false && ($perms & 0077) !== 0) {
            @chmod($file, 0600);
        }
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $owner = @fileowner(dirname($file));
            $group = @filegroup(dirname($file));
            if ($owner !== false && @fileowner($file) !== $owner) {
                @chown($file, $owner);
            }
            if ($group !== false && @filegroup($file) !== $group) {
                @chgrp($file, $group);
            }
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
