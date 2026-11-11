<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib\RestAPI\Records;

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
     * records-replace, with mode replace.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function import(RecordsApi $api, array $data, string $mode, string $by): array
    {
        $dryRun = filter_var($data['dryRun'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $api->import($data['csv'] ?? null, $mode, $dryRun, $by, time());
    }
}
