<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib\RestAPI\Statuses;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleStatusLookup\Lib\RecordsApi;
use Modules\ModuleStatusLookup\Lib\RestAPI\Api;

/** The statuses records can have, in the order staff see them. */
final class Processor
{
    public static function callback(array $request): PBXApiResult
    {
        return Api::run($request, __METHOD__, static fn (RecordsApi $api): PBXApiResult|array =>
            ($request['action'] ?? '') === 'getList' ? ['statuses' => $api->statuses()] : Api::notAllowed());
    }
}
