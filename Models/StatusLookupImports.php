<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * One CSV import that was applied: when, by whom, which file and mode, what
 * it changed, and the rows it rejected (Lib\Importer).
 *
 * @Indexes(
 *     [name='imported_at', columns=['imported_at'], type='']
 * )
 */
class StatusLookupImports extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /** @Column(type="integer", nullable=false) */
    public $imported_at;

    /** @Column(type="string", nullable=true) */
    public $imported_by = '';

    /** @Column(type="string", nullable=true) */
    public $file_name = '';

    /**
     * update (add and update) or replace (also delete records not in the file).
     *
     * @Column(type="string", nullable=false)
     */
    public $mode;

    /** @Column(type="integer", nullable=true, default="0") */
    public $added = 0;

    /** @Column(type="integer", nullable=true, default="0") */
    public $updated = 0;

    /** @Column(type="integer", nullable=true, default="0") */
    public $unchanged = 0;

    /** @Column(type="integer", nullable=true, default="0") */
    public $deleted = 0;

    /** @Column(type="integer", nullable=true, default="0") */
    public $rejected = 0;

    /**
     * The rejected rows (row, reason, ID), as JSON; at most Importer::MAX_REPORT.
     *
     * @Column(type="string", nullable=true)
     */
    public $report = '';

    public function initialize(): void
    {
        $this->setSource('m_StatusLookupImports');
        parent::initialize();
    }
}
