<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use MikoPBX\PBXCoreREST\Lib\Extensions\DropdownsAction;
use PDO;
use Throwable;

/**
 * What the page picks from in the PBX: sound files, destinations, and what
 * already sends callers to the line. Reads the core's configuration
 * database (read only) and its own destination list.
 */
final class PbxOptions
{
    /**
     * The PBX's own sound files, with the stored path the page plays a
     * preview from (through the core's sound-files:playback endpoint).
     *
     * @return list<array{id: int, name: string, path: string}>
     */
    public static function soundFiles(PDO $core): array
    {
        $rows = $core->query("SELECT id, name, path FROM m_SoundFiles WHERE category = 'custom' ORDER BY name, id")->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'path' => (string)$r['path']], $rows);
    }

    /**
     * Where callers can be sent: the same list as the core's destination
     * pickers, without outside numbers, the line's own number, and system
     * entries without a number (the line sends callers on by number).
     *
     * @return list<array{value: string, name: string, type: string}>
     */
    public static function destinations(PDO $core, string $ownNumber): array
    {
        $external = array_flip($core->query("SELECT number FROM m_Extensions WHERE type = 'EXTERNAL'")->fetchAll(PDO::FETCH_COLUMN));
        $result = DropdownsAction::getForSelect('routing');
        $list = [];
        foreach ($result->success ? $result->data : [] as $row) {
            $value = (string)($row['value'] ?? '');
            if (preg_match(Lookup::NUMBER_PATTERN, $value) !== 1 || isset($external[$value]) || $value === $ownNumber) {
                continue;
            }
            // The core's names carry an icon as HTML; the page shows plain text.
            $name = trim(html_entity_decode(strip_tags((string)($row['name'] ?? $value)), ENT_QUOTES));
            if (!str_contains($name, $value)) {
                $name .= " ($value)";
            }
            $list[] = ['value' => $value, 'name' => $name, 'type' => (string)($row['type'] ?? '')];
        }

        return $list;
    }

    /**
     * Employees' extensions, for the test call.
     *
     * @return list<array{number: string, name: string}>
     */
    public static function extensions(PDO $core): array
    {
        $rows = $core->query("SELECT number, callerid FROM m_Extensions WHERE type = 'SIP' AND userid IS NOT NULL ORDER BY number")->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(static fn (array $r): array => ['number' => (string)$r['number'], 'name' => (string)$r['callerid']],
            array_filter($rows, static fn (array $r): bool => preg_match('/^[0-9]{2,8}\z/', (string)$r['number']) === 1)));
    }

    /**
     * What sends callers to a number: incoming routes, IVR menu options and
     * timeouts, and off-work time rules.
     *
     * @return list<array{kind: string, name: string}>
     */
    public static function numberUsers(PDO $core, string $number): array
    {
        if ($number === '') {
            return [];
        }
        $queries = [
            'route'   => 'SELECT rulename AS name, number AS detail FROM m_IncomingRoutingTable WHERE extension = ? ORDER BY priority',
            'ivr'     => 'SELECT name, extension AS detail FROM m_IvrMenu WHERE timeout_extension = ?
                          UNION SELECT m.name, a.digits FROM m_IvrMenuActions a JOIN m_IvrMenu m ON m.uniqid = a.ivr_menu_id WHERE a.extension = ?',
            'offwork' => 'SELECT description AS name, \'\' AS detail FROM m_OutWorkTimes WHERE extension = ?',
        ];
        $users = [];
        foreach ($queries as $kind => $sql) {
            try {
                $stmt = $core->prepare($sql);
                $stmt->execute(array_fill(0, substr_count($sql, '?'), $number));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $users[] = ['kind' => $kind, 'name' => (string)$row['name'], 'detail' => (string)$row['detail']];
                }
            } catch (Throwable) {
                continue;   // a table this PBX version doesn't have
            }
        }

        return $users;
    }
}
