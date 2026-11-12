<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * One call to the lookup line. Written by the worker only (Lib\CallLogStore).
 *
 * @Indexes(
 *     [name='linkedid', columns=['linkedid'], type=''],
 *     [name='started_at', columns=['started_at'], type=''],
 *     [name='caller_started', columns=['caller', 'started_at'], type='']
 * )
 */
class StatusLookupCallLog extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * The call's linkedid, as in the PBX's call history.
     *
     * @Column(type="string", nullable=false)
     */
    public $linkedid;

    /** @Column(type="integer", nullable=false) */
    public $started_at;

    /**
     * When the caller left the lookup: hung up, or was sent on.
     *
     * @Column(type="integer", nullable=false)
     */
    public $ended_at;

    /**
     * The caller's number, digits only ('' when withheld). The whole row
     * is deleted after the retention period (Retention).
     *
     * @Column(type="string", nullable=true)
     */
    public $caller = '';

    /**
     * The last valid ID the caller entered.
     *
     * @Column(type="string", nullable=true)
     */
    public $id_entered = '';

    /** @Column(type="integer", nullable=true, default="0") */
    public $tries = 0;

    /**
     * Failed tries: not found, expired, a wrong PIN, another caller's
     * number, or a record that can't be told (no PIN, no phone).
     *
     * @Column(type="integer", nullable=true, default="0")
     */
    public $misses = 0;

    /**
     * How the call ended: hit, operator, too_many, rate_limited, fault,
     * inactive or abandoned.
     *
     * @Column(type="string", nullable=false)
     */
    public $outcome;

    /**
     * The last lookup's result (Lookup::RESULTS).
     *
     * @Column(type="string", nullable=true)
     */
    public $last_result = '';

    /** @Column(type="string", nullable=true) */
    public $status_code = '';

    /**
     * The number the caller was sent to, '' when the call ended in the lookup.
     *
     * @Column(type="string", nullable=true)
     */
    public $target = '';

    /** @Column(type="string", nullable=true, default="0") */
    public $is_test = '0';

    public function initialize(): void
    {
        $this->setSource('m_StatusLookupCallLog');
        parent::initialize();
    }
}
