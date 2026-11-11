<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib\RestAPI\Stats;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleStatusLookup\Lib\RecordsApi;
use Modules\ModuleStatusLookup\Lib\RestAPI\Api;

/** Activity totals: stats?from=YYYY-MM-DD&to=YYYY-MM-DD (PBX days, up to a year). */
final class Processor
{
    public static function callback(array $request): PBXApiResult
    {
        return Api::run($request, __METHOD__, static fn (RecordsApi $api, array $data): PBXApiResult|array =>
            ($request['action'] ?? '') === 'getList' ? $api->stats($data['from'] ?? null, $data['to'] ?? null) : Api::notAllowed());
    }
}
