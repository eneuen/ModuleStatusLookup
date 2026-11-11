<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use DateTimeZone;
use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Common\Models\SoundFiles;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Core\Workers\Libs\WorkerModelsEvents\Actions\ReloadDialplanAction;
use MikoPBX\Core\Workers\WorkerModelsEvents;
use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\Modules\PbxExtensionUtils;
use Throwable;

/**
 * Module hooks. The dialplan itself is built by DialplanBuilder from the
 * settings.
 *
 * This class lives on in long-running core workers, so every hook reads the
 * settings afresh. If they can't be read, the hooks add nothing and the
 * error is logged.
 */
class StatusLookupConf extends ConfigClass
{
    public const string MODULE_ID = 'ModuleStatusLookup';

    /** The PBX's own default when PBXTimezone is not set. */
    private const string DEFAULT_TIMEZONE = 'Europe/Moscow';

    /** The line's number in [internal]: reached by routes, IVR menus and phones. */
    public function extensionGenInternal(): string
    {
        return $this->builder()?->internal() ?? '';
    }

    /** The line's number in [internal-transfer]: reached by transfers from phones. */
    public function extensionGenInternalTransfer(): string
    {
        return $this->builder()?->internalTransfer() ?? '';
    }

    /** The lookup context and its hand-off. */
    public function extensionGenContexts(): string
    {
        return $this->builder()?->contexts() ?? '';
    }

    /** The worker that writes the call log. */
    public function getModuleWorkers(): array
    {
        return [
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_BEANSTALK,
                'worker' => WorkerStatusLookup::class,
            ],
        ];
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

    /** The module's own database file. */
    public static function moduleDbFile(): string
    {
        return PbxExtensionUtils::getModuleDir(self::MODULE_ID) . '/db/module.db';
    }

    /**
     * The PBX's time zone, read afresh each time: long-running workers keep
     * the zone they started with.
     */
    public static function timezone(): DateTimeZone
    {
        $name = (string)PbxSettings::getValueByKey(PbxSettings::PBX_TIMEZONE);
        try {
            return new DateTimeZone($name !== '' ? $name : self::DEFAULT_TIMEZONE);
        } catch (Throwable) {
            return new DateTimeZone(self::DEFAULT_TIMEZONE);
        }
    }

    /** A PBX sound file ID as a Playback path (without extension), or null when it is gone. */
    public static function promptPath(int $soundFileId): ?string
    {
        if ($soundFileId <= 0) {
            return null;
        }
        $file = SoundFiles::findFirstById($soundFileId);
        if ($file === null || (string)$file->path === '') {
            return null;
        }
        $path = SoundFiles::resolveAsteriskAudioPath((string)$file->path);

        return $path === '' ? null : $path;
    }

    private function builder(): ?DialplanBuilder
    {
        try {
            $settings = Settings::load(Databases::openReadOnly(self::moduleDbFile()));
            $number = (string)$settings['internal_number'];
            if ($number !== '' && !CoreNumber::isRegistered($number)) {
                SystemMessages::sysLogMsg(static::class, "The lookup line's number $number is not registered to the module; no dialplan for it.", LOG_WARNING);
                $settings['internal_number'] = '';
            }

            return new DialplanBuilder(
                $settings,
                (string)PbxSettings::getValueByKey(PbxSettings::PBX_LANGUAGE),
                Directories::getDir(Directories::AST_SOUNDS_DIR),
                [self::class, 'promptPath'],
            );
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Lookup dialplan not generated: ' . $e->getMessage(), LOG_ERR);

            return null;
        }
    }
}
