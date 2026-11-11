<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * Calls per day and outcome, with no phone numbers. Kept after the call log
 * is pruned, so long-term trends survive. Written by the worker only.
 *
 * @Indexes(
 *     [name='unique_day_outcome', columns=['day', 'outcome', 'to_person'], type='UNIQUE']
 * )
 */
class StatusLookupDailyCounts extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * YYYY-MM-DD, PBX time.
     *
     * @Column(type="string", nullable=false)
     */
    public $day;

    /** @Column(type="string", nullable=false) */
    public $outcome;

    /**
     * '1' when the caller was sent on to a person.
     *
     * @Column(type="string", nullable=false, default="0")
     */
    public $to_person = '0';

    /** @Column(type="integer", nullable=false, default="0") */
    public $calls = 0;

    /** @Column(type="integer", nullable=false, default="0") */
    public $misses = 0;

    public function initialize(): void
    {
        $this->setSource('m_StatusLookupDailyCounts');
        parent::initialize();
    }
}
