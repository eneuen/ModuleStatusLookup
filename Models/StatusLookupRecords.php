<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * One item a caller can look up: a ticket, work order, prescription or order.
 *
 * The core creates the table from these annotations; the module reads and
 * writes it with plain SQL.
 *
 * @Indexes(
 *     [name='unique_lookup_id', columns=['lookup_id'], type='UNIQUE'],
 *     [name='status_code', columns=['status_code'], type=''],
 *     [name='updated_at', columns=['updated_at'], type='']
 * )
 */
class StatusLookupRecords extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * The digits the caller types, as text: leading zeros count.
     *
     * @Column(type="string", nullable=false)
     */
    public $lookup_id;

    /** @Column(type="string", nullable=false) */
    public $status_code;

    /**
     * For staff only; never spoken.
     *
     * @Column(type="string", nullable=true)
     */
    public $note = '';

    /**
     * HMAC of the record's PIN, or empty for none.
     *
     * @Column(type="string", nullable=true)
     */
    public $pin_hash = '';

    /**
     * The customer's phone number, digits only, for statuses that check the
     * caller ID.
     *
     * @Column(type="string", nullable=true)
     */
    public $phone = '';

    /**
     * A date spoken after the status sentence when the status says so
     * (YYYY-MM-DD), such as a shipping date.
     *
     * @Column(type="string", nullable=true)
     */
    public $status_date = '';

    /**
     * The last day the record is read out (YYYY-MM-DD, PBX time); after it
     * the record counts as not found.
     *
     * @Column(type="string", nullable=true)
     */
    public $expires_on = '';

    /** @Column(type="integer", nullable=true) */
    public $updated_at;

    /**
     * Who made the last change: an admin login or an API key's name.
     *
     * @Column(type="string", nullable=true)
     */
    public $updated_by = '';

    /**
     * How the last change came in: form, csv or api.
     *
     * @Column(type="string", nullable=true)
     */
    public $source = '';

    public function initialize(): void
    {
        $this->setSource('m_StatusLookupRecords');
        parent::initialize();
    }
}
