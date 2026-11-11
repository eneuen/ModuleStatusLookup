<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

/**
 * The generic prompts that ship with the module, in English and Russian.
 *
 * The recordings live in Sounds/<language>/<key>.wav. When the module is
 * enabled, the core copies them into Asterisk's sounds folder for that
 * language as "modulestatuslookup-<key>" and converts them to its other
 * formats (SoundFilesConf::installModuleSounds); disabling removes them.
 *
 * The settings store a built-in prompt as a negative ID in the usual prompt
 * fields. The PBX's own sound-file IDs are always positive, so the two never
 * clash. Only the names in this fixed list ever reach the dialplan.
 *
 * Plain PHP, no core dependencies, so it is unit-tested without a PBX.
 */
final class BuiltInPrompts
{
    /** The prompt's key => its ID in the settings' prompt fields. */
    public const array IDS = [
        'welcome'   => -1,
        'enter-id'  => -2,
        'enter-pin' => -3,
        'invalid'   => -4,
        'not-found' => -5,
        'repeat'    => -6,
        'goodbye'   => -7,
        'transfer'  => -8,
        'fault'     => -9,
    ];

    /** The languages the module ships recordings in. */
    public const array LANGUAGES = ['en-en', 'ru-ru'];

    /** How the core names a feature module's sounds: the module ID in lower case, then a dash. */
    public const string NAME_PREFIX = 'modulestatuslookup-';

    public static function isBuiltIn(int $id): bool
    {
        return in_array($id, self::IDS, true);
    }

    /** The prompt's key, such as "enter-id", or null for any other ID. */
    public static function key(int $id): ?string
    {
        $key = array_search($id, self::IDS, true);

        return is_string($key) ? $key : null;
    }

    /** The folder under the module directory that the core installs sounds from. */
    public const string SOUNDS_DIR = 'Sounds';

    /**
     * The language a built-in prompt plays in: the channel's own when the
     * module ships it, else English.
     */
    public static function languageFor(string $language): string
    {
        return in_array($language, self::LANGUAGES, true) ? $language : self::LANGUAGES[0];
    }

    /**
     * Whether the module ships this prompt's recording for a channel
     * language (or the English one it falls back to).
     *
     * @param string $moduleDir The module's folder.
     */
    public static function available(string $moduleDir, int $id, string $language): bool
    {
        $key = self::key($id);

        return $key !== null
            && is_file(rtrim($moduleDir, '/') . '/' . self::SOUNDS_DIR . '/' . self::languageFor($language) . "/$key.wav");
    }

    /**
     * The catalogue for the page, in display order, with the file it can
     * play for a preview in each language: the core installs the prompts
     * into its sounds folder and converts them; the browser-friendly .webm
     * copy is preferred, else the shipped .wav.
     *
     * @param string|null $soundsDir The PBX's Asterisk sounds folder, or null for no previews.
     *
     * @return list<array{id: int, key: string, preview: array<string, string>}>
     */
    public static function catalogue(?string $soundsDir = null): array
    {
        $list = [];
        foreach (self::IDS as $key => $id) {
            $preview = [];
            foreach ($soundsDir === null ? [] : self::LANGUAGES as $language) {
                $base = rtrim($soundsDir, '/') . "/$language/" . self::NAME_PREFIX . $key;
                $preview[$language] = is_file("$base.webm") ? "$base.webm" : "$base.wav";
            }
            $list[] = ['id' => $id, 'key' => $key, 'preview' => $preview];
        }

        return $list;
    }

    /**
     * What to Playback for a built-in prompt, or null for any other ID.
     *
     * In a language the module ships, the bare name, so Asterisk picks the
     * channel language's folder. In any other language, the English
     * recording by its full path, so the caller hears English rather than
     * nothing.
     *
     * @param string $soundsDir The PBX's Asterisk sounds folder.
     */
    public static function playback(int $id, string $language, string $soundsDir): ?string
    {
        $key = self::key($id);
        if ($key === null) {
            return null;
        }
        if (in_array($language, self::LANGUAGES, true)) {
            return self::NAME_PREFIX . $key;
        }

        return rtrim($soundsDir, '/') . '/' . self::LANGUAGES[0] . '/' . self::NAME_PREFIX . $key;
    }
}
