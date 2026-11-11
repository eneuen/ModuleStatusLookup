<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib\RestAPI\Records;

use MikoPBX\PBXCoreREST\Attributes\ApiResource;
use MikoPBX\PBXCoreREST\Attributes\HttpMapping;
use MikoPBX\PBXCoreREST\Attributes\ResourceSecurity;
use MikoPBX\PBXCoreREST\Attributes\SecurityType;
use MikoPBX\PBXCoreREST\Controllers\BaseRestController;
use MikoPBX\PBXCoreREST\Lib\Waf\Attributes\WafExempt;

/**
 * /pbxcore/api/v3/module-status-lookup/records
 *
 *   GET    records?search=&status=&expired=1&page=   list (50 a page)
 *   GET    records/{lookup_id}                       one record
 *   PUT    records/{lookup_id}                       create or change one
 *   DELETE records/{lookup_id}                       delete one
 *   POST   records:import                            CSV import, add and update (dryRun to preview)
 *
 * Routing only; the core loads this class in every REST request, so it
 * stays trivial. The work is done by Processor in the core's API worker.
 */
#[ApiResource(
    path: '/pbxcore/api/v3/module-status-lookup/records',
    tags: ['Status Lookup'],
    description: 'Status Lookup records: the IDs callers type and their statuses',
    processor: Processor::class
)]
#[ResourceSecurity('module_status_lookup_records', requirements: [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN])]
#[HttpMapping(
    mapping: ['GET' => ['getList', 'getRecord'], 'PUT' => ['update'], 'DELETE' => ['delete'], 'POST' => ['import']],
    resourceLevelMethods: ['getRecord', 'update', 'delete'],
    collectionLevelMethods: ['getList', 'import'],
    customMethods: ['import'],
    idPattern: '[0-9]+'
)]
// Notes and CSV files are free text, which the PBX's request filter would otherwise read as SQL.
#[WafExempt(scopes: ['body-scan'], reason: 'Record notes and CSV imports are free text')]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    /** An import of a large file may take longer than the core's usual 30 s wait. */
    public function sendRequestToBackendWorker(
        string $processor,
        string $actionName,
        mixed $payload = null,
        string $moduleName = '',
        int $maxTimeout = 30,
        int $priority = 0
    ): void {
        parent::sendRequestToBackendWorker($processor, $actionName, $payload, $moduleName, $actionName === 'import' ? 120 : $maxTimeout, $priority);
    }
}
