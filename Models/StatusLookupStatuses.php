<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Models;

use MikoPBX\Common\Models\Extensions;
use MikoPBX\Common\Models\SoundFiles;
use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;

/**
 * One status a record can have, and what the caller hears and gets next.
 *
 * The core creates the table from these annotations; the module reads and
 * writes it with plain SQL.
 *
 * @Indexes(
 *     [name='unique_code', columns=['code'], type='UNIQUE']
 * )
 */
class StatusLookupStatuses extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * Used in CSV files and the API: A-Z, 0-9 and _, 1 to 32 of them.
     *
     * @Column(type="string", nullable=false)
     */
    public $code;

    /**
     * Shown to staff only.
     *
     * @Column(type="string", nullable=true)
     */
    public $name = '';

    /**
     * The sentence's wording, for whoever records it (staff only); filled
     * from a template.
     *
     * @Column(type="string", nullable=true)
     */
    public $script = '';

    /**
     * The PBX sound file with the status sentence.
     *
     * @Column(type="integer", nullable=true)
     */
    public $prompt_id;

    /**
     * Played after the sentence: none, id (the ID's digits) or date (the
     * record's date).
     *
     * @Column(type="string", nullable=true, default="none")
     */
    public $spoken_extra = 'none';

    /**
     * After the sentence: hangup (goodbye), repeat (1 to repeat, 0 for a
     * person) or transfer (straight to transfer_number).
     *
     * @Column(type="string", nullable=true, default="repeat")
     */
    public $after_action = 'repeat';

    /** @Column(type="string", nullable=true) */
    public $transfer_number = '';

    /** @Column(type="string", nullable=true, default="0") */
    public $requires_pin = '0';

    /** @Column(type="string", nullable=true, default="0") */
    public $requires_callerid = '0';

    /** @Column(type="integer", nullable=true, default="0") */
    public $sort_order = 0;

    public function initialize(): void
    {
        $this->setSource('m_StatusLookupStatuses');
        parent::initialize();
    }

    /**
     * Protects what statuses use: the core refuses to delete a sound file a
     * status plays, or a number a status transfers to.
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
        if ($calledModelObject instanceof Extensions) {
            $calledModelObject->hasMany('number', self::class, 'transfer_number', [
                'alias'      => 'ModuleStatusLookupStatusTransfer',
                'foreignKey' => [
                    'allowNulls' => true,
                    'message'    => 'Models\\ModuleStatusLookupStatusTransfer',
                    'action'     => Relation::ACTION_RESTRICT,
                ],
            ]);
        }
        if ($calledModelObject instanceof SoundFiles) {
            $calledModelObject->hasMany('id', self::class, 'prompt_id', [
                'alias'      => 'ModuleStatusLookupStatusPrompt',
                'foreignKey' => [
                    'allowNulls' => true,
                    'message'    => 'Models\\ModuleStatusLookupStatusPrompt',
                    'action'     => Relation::ACTION_RESTRICT,
                ],
            ]);
        }
    }
}
