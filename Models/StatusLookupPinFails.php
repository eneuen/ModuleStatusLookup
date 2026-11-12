<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * Wrong PINs typed for an ID, one row each, written by the PIN script the
 * moment one is typed. The lookup stops asking for an ID's PIN after too
 * many in a day, whoever calls (Lookup::PIN_FAILS_LIMIT). Deleted by the
 * call log's retention and by Forget.
 *
 * @Indexes(
 *     [name='pin_id_started', columns=['pin_id', 'started_at'], type=''],
 *     [name='started_at', columns=['started_at'], type='']
 * )
 */
class StatusLookupPinFails extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * The ID the wrong PINs were typed for.
     *
     * @Column(type="string", nullable=false)
     */
    public $pin_id;

    /**
     * When the wrong PIN was typed (epoch seconds).
     *
     * @Column(type="integer", nullable=false)
     */
    public $started_at;

    /** @Column(type="integer", nullable=false, default="0") */
    public $fails = 0;

    public function initialize(): void
    {
        $this->setSource('m_StatusLookupPinFails');
        parent::initialize();
    }
}
