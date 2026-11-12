<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib\RestAPI\Records;

use MikoPBX\Core\System\SystemMessages;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleStatusLookup\Lib\EditorException;
use Modules\ModuleStatusLookup\Lib\RecordsApi;
use Modules\ModuleStatusLookup\Lib\RestAPI\Api;

/**
 * The records resource, run by the core's API worker. PUT answers 201 when
 * it created the record and 200 when it changed one; GET and DELETE of an
 * ID with no record answer 404.
 */
final class Processor
{
    public static function callback(array $request): PBXApiResult
    {
        return Api::run($request, __METHOD__, static function (RecordsApi $api, array $data, string $by) use ($request): PBXApiResult|array {
            switch ($request['action'] ?? '') {
                case 'getList':
                    return $api->list($data, time());
                case 'getRecord':
                    return $api->get(Api::id($data)) ?? throw new EditorException('not_found');
                case 'update':
                    $id = Api::id($data);
                    unset($data['id']);
                    $done = $api->put($id, $data, $by, time());
                    return $done['created'] ? Api::status(201, $done['record']) : $done['record'];
                case 'delete':
                    if (!$api->delete(Api::id($data))) {
                        throw new EditorException('not_found');
                    }
                    // Deleting is the one write a records key can do that leaves no record behind: say who did it.
                    SystemMessages::sysLogMsg(self::class, "$by deleted a record through the API", LOG_WARNING);
                    return ['deleted' => true];
                case 'import':
                    return self::import($api, $data, 'update', $by);
                default:
                    return Api::notAllowed();
            }
        });
    }

    /**
     * A CSV import: {"csv": "...", "dryRun": true}. Also used by
     * records-replace, with mode replace. Anything else in the request is
     * refused, and dryRun must be clearly true or false: a misspelt dry run
     * must never import for real (on records-replace, it would delete).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     *
     * @throws EditorException
     */
    public static function import(RecordsApi $api, array $data, string $mode, string $by): array
    {
        unset($data['id']);
        $unknown = array_diff(array_keys($data), $mode === 'replace' ? ['csv', 'dryRun', 'allowLargeDelete'] : ['csv', 'dryRun']);
        if ($unknown !== []) {
            throw new EditorException('unknown_fields', ['fields' => implode(',', array_map('strval', $unknown))]);
        }
        $dryRun = filter_var($data['dryRun'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($dryRun === null) {
            throw new EditorException('field_type', ['field' => 'dryRun']);
        }

        $allowLarge = filter_var($data['allowLargeDelete'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($allowLarge === null) {
            throw new EditorException('field_type', ['field' => 'allowLargeDelete']);
        }
        $result = $api->import($data['csv'] ?? null, $mode, $dryRun, $by, time(), $allowLarge);
        if (!$dryRun && $mode === 'replace' && $result['deleted'] > 0) {
            SystemMessages::sysLogMsg(self::class, "$by replaced all records through the API, deleting {$result['deleted']}", LOG_WARNING);
        }

        return $result;
    }
}
