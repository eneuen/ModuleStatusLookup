<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib\RestAPI\RecordsReplace;

use MikoPBX\PBXCoreREST\Attributes\ApiResource;
use MikoPBX\PBXCoreREST\Attributes\HttpMapping;
use MikoPBX\PBXCoreREST\Attributes\ResourceSecurity;
use MikoPBX\PBXCoreREST\Attributes\SecurityType;
use MikoPBX\PBXCoreREST\Controllers\BaseRestController;
use MikoPBX\PBXCoreREST\Lib\Waf\Attributes\WafExempt;

/**
 * /pbxcore/api/v3/module-status-lookup/records-replace
 *
 *   POST records-replace:import   the CSV becomes the full list: records not in it are deleted
 *
 * Its own resource, so an API key that may update records isn't also
 * allowed to delete them all: it has to be granted this path as well.
 */
#[ApiResource(
    path: '/pbxcore/api/v3/module-status-lookup/records-replace',
    tags: ['Status Lookup'],
    description: 'Status Lookup: replace all records with a CSV file',
    processor: Processor::class
)]
#[ResourceSecurity('module_status_lookup_records_replace', requirements: [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN])]
#[HttpMapping(
    mapping: ['POST' => ['import']],
    collectionLevelMethods: ['import'],
    customMethods: ['import']
)]
#[WafExempt(scopes: ['body-scan'], reason: 'CSV imports are free text')]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

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
