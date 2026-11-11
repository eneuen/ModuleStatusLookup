<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib\RestAPI\RecordsReplace;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleStatusLookup\Lib\RecordsApi;
use Modules\ModuleStatusLookup\Lib\RestAPI\Api;
use Modules\ModuleStatusLookup\Lib\RestAPI\Records\Processor as RecordsProcessor;

/** Replace All, run by the core's API worker. */
final class Processor
{
    public static function callback(array $request): PBXApiResult
    {
        return Api::run($request, __METHOD__, static fn (RecordsApi $api, array $data, string $by): PBXApiResult|array =>
            ($request['action'] ?? '') === 'import' ? RecordsProcessor::import($api, $data, 'replace', $by) : Api::notAllowed());
    }
}
