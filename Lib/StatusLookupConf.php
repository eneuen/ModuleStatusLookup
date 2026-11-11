<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use MikoPBX\Core\Workers\Libs\WorkerModelsEvents\Actions\ReloadDialplanAction;
use MikoPBX\Core\Workers\WorkerModelsEvents;
use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\Modules\PbxExtensionUtils;

/**
 * Module hooks.
 *
 * This class lives on in long-running core workers, so every hook reads the
 * settings afresh.
 */
class StatusLookupConf extends ConfigClass
{
    public const string MODULE_ID = 'ModuleStatusLookup';

    /** The module's own database file. */
    public static function moduleDbFile(): string
    {
        return PbxExtensionUtils::getModuleDir(self::MODULE_ID) . '/db/module.db';
    }

    /** Enabling a module doesn't reload the dialplan by itself. */
    public function onAfterModuleEnable(): void
    {
        WorkerModelsEvents::invokeAction(ReloadDialplanAction::class);
    }

    /** Removes the module's dialplan once its hooks are no longer called. */
    public function onAfterModuleDisable(): void
    {
        WorkerModelsEvents::invokeAction(ReloadDialplanAction::class);
    }
}
