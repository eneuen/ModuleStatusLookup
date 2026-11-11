<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Setup;

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;
use Modules\ModuleStatusLookup\Lib\CoreNumber;
use Modules\ModuleStatusLookup\Lib\Databases;
use Modules\ModuleStatusLookup\Lib\IvrNumber;
use Modules\ModuleStatusLookup\Lib\Settings;
use Throwable;

/**
 * Installer. The base class creates the tables from the model annotations,
 * links agi-bin/ into Asterisk's AGI folder and adds the sidebar entry.
 */
class PbxExtensionSetup extends PbxExtensionSetupBase
{
    /** The sidebar entry, in the modules group with a magnifier icon. */
    public function addToSidebar(): bool
    {
        $key = "AdditionalMenuItem$this->moduleUniqueID";
        $menuSettings = PbxSettings::findFirstByKey($key) ?? new PbxSettings();
        $menuSettings->key = $key;
        $menuSettings->value = json_encode([
            'uniqid'        => $this->moduleUniqueID,
            'group'         => 'modules',
            'iconClass'     => 'search',
            'caption'       => "Breadcrumb$this->moduleUniqueID",
            'showAtSidebar' => true,
        ]);

        return $menuSettings->save();
    }

    /**
     * After the core creates or extends the tables: makes sure the settings
     * row exists and the IVR has a number in the core's extensions table. A
     * new install takes back the number an earlier install left behind
     * (because a route still pointed at it), else 2300 or the first free
     * number up to 2399; a kept database keeps its number. If no number can be had, the install still
     * succeeds and the admin chooses one on the module page.
     */
    public function installDB(): bool
    {
        if (!parent::installDB()) {
            return false;
        }
        try {
            $db = Databases::openWritable($this->moduleDir . '/db/module.db');
            Settings::ensureRow($db);
            $number = (string)Settings::load($db)['internal_number'];
            if ($number === '') {
                $number = IvrNumber::forNewInstall(CoreNumber::taken(), CoreNumber::leftovers()) ?? '';
            }
            if ($number !== '') {
                CoreNumber::register($number);
                Settings::save($db, ['internal_number' => $number]);
            }
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'The lookup number was not registered: ' . $e->getMessage(), LOG_WARNING);
        }

        return true;
    }

    /**
     * Also removes what lives outside the module folder: the AGI links (the
     * core creates them on install but never removes them).
     */
    public function unInstallFiles(bool $keepSettings = false): bool
    {
        $agiDir = Directories::getDir(Directories::AST_AGI_BIN_DIR);
        foreach (glob($this->moduleDir . '/agi-bin/*.php') ?: [] as $script) {
            $link = $agiDir . '/' . basename($script);
            if (is_link($link) && str_starts_with((string)readlink($link), $this->moduleDir . '/')) {
                unlink($link);
            }
        }

        return parent::unInstallFiles($keepSettings);
    }

    /**
     * Also removes the sidebar entry's setting, which the core leaves in its
     * settings table, and, without kept settings, releases the IVR's number
     * in the core. The core refuses while a route or rule still points at the
     * number; that is logged, and the admin sees the number in use there.
     */
    public function unInstallDB(bool $keepSettings = false): bool
    {
        PbxSettings::findFirstByKey("AdditionalMenuItem$this->moduleUniqueID")?->delete();
        if (!$keepSettings) {
            $this->releaseNumber();
        }

        return parent::unInstallDB($keepSettings);
    }

    private function releaseNumber(): void
    {
        try {
            $number = (string)Settings::load(Databases::openReadOnly($this->moduleDir . '/db/module.db'))['internal_number'];
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'The lookup number was not released: ' . $e->getMessage(), LOG_WARNING);
            return;
        }
        if ($number === '') {
            return;
        }
        $reasons = CoreNumber::unregister($number);
        if ($reasons !== []) {
            // The core's reasons are HTML for its own pages.
            $text = trim((string)preg_replace('/\s+/', ' ', strip_tags(implode(' ', $reasons))));
            SystemMessages::sysLogMsg(static::class, "Number $number kept, because something still uses it: $text", LOG_WARNING);
        }
    }
}
