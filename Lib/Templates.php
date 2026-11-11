<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use PDO;

/**
 * Business templates: a starting set of statuses, with the wording to record
 * for each, and the ID lengths that business uses. One JSON file per
 * template in Setup/templates/, so new industries can be added as files.
 *
 * Applying a template only adds statuses the line doesn't have yet (by
 * code); it never changes or deletes statuses or records. The first
 * template applied is remembered, and sets the ID lengths when there are no
 * records yet.
 *
 * A status may ask for the record's PIN ("pin": true) or only answer the
 * record's own phone number ("callerid": true); the pharmacy template asks
 * for PINs.
 *
 * A template status that "goes to a person" puts the caller through to the
 * operator destination when one is set; otherwise it offers the repeat menu,
 * where 0 reaches the operator. Its sentence still has to be recorded and
 * chosen on the Statuses tab: the line treats a status without one as a
 * fault and puts the caller through.
 *
 * Plain PHP and PDO, no core dependencies, so it is unit-tested without a PBX.
 */
final class Templates
{
    public const array AFTER = ['repeat', 'hangup', 'person'];

    public function __construct(private readonly string $dir)
    {
    }

    /**
     * Every valid template, in order, with texts in $language (or English).
     * A file that isn't a valid template is skipped.
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $language): array
    {
        $list = [];
        foreach (glob(rtrim($this->dir, '/') . '/*.json') ?: [] as $file) {
            $template = self::read($file);
            if ($template !== null) {
                $list[] = self::localized($template, $language);
            }
        }
        usort($list, static fn (array $a, array $b): int => [$a['order'], $a['key']] <=> [$b['order'], $b['key']]);

        return $list;
    }

    /**
     * Adds the template's statuses the line doesn't have yet.
     *
     * @return array{added: list<string>, kept: list<string>} Codes added, and codes that already existed.
     *
     * @throws EditorException
     */
    public function apply(PDO $db, string $key, string $language, string $operator): array
    {
        $template = preg_match('/^[a-z0-9_-]{1,32}\z/', $key) === 1 ? self::read(rtrim($this->dir, '/') . "/$key.json") : null;
        if ($template === null || $template['key'] !== $key) {
            throw new EditorException('template');
        }
        $template = self::localized($template, $language);
        $existing = array_flip($db->query('SELECT code FROM m_StatusLookupStatuses')->fetchAll(PDO::FETCH_COLUMN));
        $added = [];
        $kept = [];
        Databases::immediate($db, function () use ($db, $template, $key, $operator, $existing, &$added, &$kept): void {
            $order = (int)$db->query('SELECT COALESCE(MAX(sort_order), 0) FROM m_StatusLookupStatuses')->fetchColumn();
            $insert = $db->prepare("INSERT INTO m_StatusLookupStatuses (code, name, script, prompt_id, spoken_extra, after_action, transfer_number,
                    requires_pin, requires_callerid, sort_order) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)");
            foreach ($template['statuses'] as $status) {
                if (isset($existing[$status['code']])) {
                    $kept[] = $status['code'];
                    continue;
                }
                if (count($existing) + count($added) >= StatusEditor::MAX_STATUSES) {
                    throw new EditorException('too_many_statuses', ['max' => StatusEditor::MAX_STATUSES]);
                }
                $toPerson = $status['after'] === 'person';
                $transfer = $toPerson && preg_match(Lookup::NUMBER_PATTERN, $operator) === 1;
                $insert->execute([$status['code'], $status['name'], $status['script'], $status['extra'],
                    $transfer ? 'transfer' : ($toPerson ? 'repeat' : $status['after']), $transfer ? $operator : '',
                    $status['pin'] ? '1' : '0', $status['callerid'] ? '1' : '0', ++$order]);
                $added[] = $status['code'];
            }
            $settings = Settings::load($db);
            if ($settings['template'] === '') {
                $values = ['template' => $key];
                // The template's ID lengths only while there are no records: lengths that left records out would make them unreachable.
                if ((int)$db->query('SELECT COUNT(*) FROM m_StatusLookupRecords')->fetchColumn() === 0) {
                    $values += ['id_min_length' => $template['id_length'][0], 'id_max_length' => $template['id_length'][1]];
                }
                Settings::save($db, $values);
            }
        });

        return ['added' => $added, 'kept' => $kept];
    }

    /**
     * A template file, checked; null when it isn't a valid template.
     *
     * @return array<string, mixed>|null
     */
    private static function read(string $file): ?array
    {
        if (!is_file($file) || filesize($file) > 65536) {
            return null;
        }
        try {
            $t = json_decode((string)file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $text = static fn (mixed $v, int $max): bool => is_array($v) && is_string($v['en'] ?? null) && $v['en'] !== ''
            && array_reduce($v, static fn (bool $ok, mixed $s): bool => $ok && is_string($s) && mb_strlen($s) <= $max
                && preg_match('/[\x00-\x1F\x7F]/', $s) !== 1, true);
        if (!is_array($t) || !is_string($t['key'] ?? null) || preg_match('/^[a-z0-9_-]{1,32}\z/', $t['key']) !== 1
            || basename($file, '.json') !== $t['key'] || !is_int($t['order'] ?? null)
            || !$text($t['name'] ?? null, 60) || !$text($t['description'] ?? null, 200)
            || !is_array($t['id_length'] ?? null) || count($t['id_length']) !== 2
            || !is_int($t['id_length'][0]) || !is_int($t['id_length'][1])
            || $t['id_length'][0] < 2 || $t['id_length'][1] > 20 || $t['id_length'][0] > $t['id_length'][1]
            || !is_array($t['statuses'] ?? null) || count($t['statuses']) > StatusEditor::MAX_STATUSES) {
            return null;
        }
        $codes = [];
        foreach ($t['statuses'] as $s) {
            if (!is_array($s) || !is_string($s['code'] ?? null) || preg_match(StatusEditor::CODE_PATTERN, $s['code']) !== 1
                || isset($codes[$s['code']]) || !$text($s['name'] ?? null, StatusEditor::MAX_NAME)
                || !$text($s['script'] ?? null, StatusEditor::MAX_SCRIPT)
                || !in_array($s['extra'] ?? null, Lookup::EXTRAS, true) || !in_array($s['after'] ?? null, self::AFTER, true)
                || !is_bool($s['pin'] ?? false) || !is_bool($s['callerid'] ?? false)) {
                return null;
            }
            $codes[$s['code']] = true;
        }

        return $t;
    }

    /**
     * @param array<string, mixed> $t
     *
     * @return array<string, mixed>
     */
    private static function localized(array $t, string $language): array
    {
        $lang = substr($language, 0, 2);
        $pick = static fn (array $v): string => $v[$lang] ?? $v['en'];

        return [
            'key'         => $t['key'],
            'order'       => $t['order'],
            'name'        => $pick($t['name']),
            'description' => $pick($t['description']),
            'id_length'   => $t['id_length'],
            'statuses'    => array_map(static fn (array $s): array => [
                'code' => $s['code'], 'name' => $pick($s['name']), 'script' => $pick($s['script']),
                'extra' => $s['extra'], 'after' => $s['after'], 'pin' => $s['pin'] ?? false, 'callerid' => $s['callerid'] ?? false,
            ], $t['statuses']),
        ];
    }
}
