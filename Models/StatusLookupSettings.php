<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Models;

use MikoPBX\Common\Models\Extensions;
use MikoPBX\Common\Models\SoundFiles;
use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;

/**
 * The lookup IVR's call flow: one row (id 1).
 *
 * Prompts are sound-file IDs: positive for the PBX's own sound files,
 * negative for the prompts the module ships (Lib\BuiltInPrompts). Each new
 * install starts with the built-in ones.
 *
 * The core creates the table from these annotations; the module reads and
 * writes it with plain SQL (Lib\Settings).
 */
class StatusLookupSettings extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * The IVR's internal number, also a row in the core's m_Extensions
     * (type MODULES).
     *
     * @Column(type="string", nullable=true)
     */
    public $internal_number = '';

    /**
     * The business template applied on first run ('' until one is chosen).
     *
     * @Column(type="string", nullable=true)
     */
    public $template = '';

    /**
     * '1' when the IVR answers calls; until then its number plays the fault
     * prompt and hangs up.
     *
     * @Column(type="string", nullable=true, default="0")
     */
    public $active = '0';

    /** @Column(type="integer", nullable=true, default="-1") */
    public $welcome_prompt_id = -1;

    /** @Column(type="integer", nullable=true, default="-2") */
    public $enter_id_prompt_id = -2;

    /** @Column(type="integer", nullable=true, default="-3") */
    public $enter_pin_prompt_id = -3;

    /** @Column(type="integer", nullable=true, default="-4") */
    public $invalid_prompt_id = -4;

    /** @Column(type="integer", nullable=true, default="-5") */
    public $not_found_prompt_id = -5;

    /** @Column(type="integer", nullable=true, default="-6") */
    public $repeat_prompt_id = -6;

    /** @Column(type="integer", nullable=true, default="-7") */
    public $goodbye_prompt_id = -7;

    /** @Column(type="integer", nullable=true, default="-8") */
    public $transfer_prompt_id = -8;

    /** @Column(type="integer", nullable=true, default="-9") */
    public $fault_prompt_id = -9;

    /** @Column(type="integer", nullable=true, default="4") */
    public $id_min_length = 4;

    /** @Column(type="integer", nullable=true, default="10") */
    public $id_max_length = 10;

    /**
     * Seconds to wait for the first key and between keys.
     *
     * @Column(type="integer", nullable=true, default="5")
     */
    public $digit_timeout = 5;

    /** @Column(type="integer", nullable=true, default="3") */
    public $max_tries = 3;

    /**
     * Where "0" and the "speak to us" after-actions send the caller.
     *
     * @Column(type="string", nullable=true)
     */
    public $operator_number = '';

    /** @Column(type="string", nullable=true) */
    public $too_many_tries_number = '';

    /**
     * Where the caller goes when a lookup fails or a destination is gone.
     *
     * @Column(type="string", nullable=true)
     */
    public $fault_number = '';

    /**
     * Missed lookups an hour from one caller number before its calls go
     * straight to the operator.
     *
     * @Column(type="integer", nullable=true, default="10")
     */
    public $miss_limit_caller = 10;

    /**
     * Missed lookups an hour from all callers together before every call goes
     * straight to the operator (rotating, spoofed caller IDs).
     *
     * @Column(type="integer", nullable=true, default="200")
     */
    public $miss_limit_total = 200;

    /**
     * Callers in the lookup at the same time; more go straight to the
     * operator. Bounds what many parallel calls can guess before any of
     * them is logged. 0 is no limit.
     *
     * @Column(type="integer", nullable=true, default="10")
     */
    public $max_concurrent = 10;

    /**
     * Days to keep callers' numbers in the call log; 0 follows the PBX's call
     * history retention.
     *
     * @Column(type="integer", nullable=true, default="0")
     */
    public $log_retention_days = 0;

    /**
     * '1' deletes expired records every night.
     *
     * @Column(type="string", nullable=true, default="0")
     */
    public $purge_expired = '0';

    public function initialize(): void
    {
        $this->setSource('m_StatusLookupSettings');
        parent::initialize();
    }

    /**
     * Claims the IVR's number in the core's extensions table, so the core
     * lists it in its destination pickers as this module's ("Module: ..."),
     * and protects what the IVR uses: the core then refuses to delete a
     * queue, IVR menu or extension it sends callers to, or a sound file it
     * plays.
     * The core attaches these relations only while the module is enabled.
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
        if ($calledModelObject instanceof Extensions) {
            $fields = [
                'internal_number'       => '',
                'operator_number'       => 'Operator',
                'too_many_tries_number' => 'TooManyTries',
                'fault_number'          => 'Fault',
            ];
            self::restrict($calledModelObject, 'number', $fields);
        }
        if ($calledModelObject instanceof SoundFiles) {
            // Built-in prompts are negative IDs and never match a sound file.
            $fields = [];
            foreach (['welcome', 'enter_id', 'enter_pin', 'invalid', 'not_found', 'repeat', 'goodbye', 'transfer', 'fault'] as $prompt) {
                $fields["{$prompt}_prompt_id"] = 'Prompt' . str_replace('_', '', ucwords($prompt, '_'));
            }
            self::restrict($calledModelObject, 'id', $fields);
        }
    }

    /**
     * @param Extensions|SoundFiles $model
     * @param array<string, string> $fields This model's field => alias suffix.
     */
    private static function restrict($model, string $key, array $fields): void
    {
        foreach ($fields as $field => $suffix) {
            $model->hasMany($key, self::class, $field, [
                'alias'      => "ModuleStatusLookupSettings$suffix",
                'foreignKey' => [
                    'allowNulls' => true,
                    'message'    => "Models\\ModuleStatusLookupSettings$suffix",
                    'action'     => Relation::ACTION_RESTRICT,
                ],
            ]);
        }
    }
}
