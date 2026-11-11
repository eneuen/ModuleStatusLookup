<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib\RestAPI\Stats;

use MikoPBX\PBXCoreREST\Attributes\ApiResource;
use MikoPBX\PBXCoreREST\Attributes\HttpMapping;
use MikoPBX\PBXCoreREST\Attributes\ResourceSecurity;
use MikoPBX\PBXCoreREST\Attributes\SecurityType;
use MikoPBX\PBXCoreREST\Controllers\BaseRestController;

/** GET /pbxcore/api/v3/module-status-lookup/stats (read only). */
#[ApiResource(
    path: '/pbxcore/api/v3/module-status-lookup/stats',
    tags: ['Status Lookup'],
    description: 'Status Lookup activity totals for a period',
    processor: Processor::class
)]
#[ResourceSecurity('module_status_lookup_stats', requirements: [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: ['GET' => ['getList']], collectionLevelMethods: ['getList'])]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;
}
