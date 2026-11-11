#!/usr/bin/php
<?php

// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * One step of a call to the lookup line, run by its dialplan:
 *
 *   lookup  looks up what the caller typed (SL_IN) and sets the SL_*
 *           variables the dialplan acts on (Lookup). On any error it sets
 *           SL_RESULT=fault, which sends the caller to a person.
 *   date    speaks the record's date (SL_DATE, epoch seconds) in the
 *           channel's language: weekday, month and day. Asterisk's
 *           SayUnixTime isn't loaded in MikoPBX; the AGI command SAY
 *           DATETIME does the same.
 *
 * Reads the module database only. No shell, no files, no network.
 */

declare(strict_types=1);

use MikoPBX\Core\Asterisk\AGI;
use MikoPBX\Core\System\SystemMessages;
use Modules\ModuleStatusLookup\Lib\Databases;
use Modules\ModuleStatusLookup\Lib\Lookup;
use Modules\ModuleStatusLookup\Lib\Settings;
use Modules\ModuleStatusLookup\Lib\StatusLookupConf;

require_once 'Globals.php';

set_time_limit(5);

$agi = new AGI();
$mode = $argv[1] ?? '';

if ($mode === 'date') {
    $epoch = (string)$agi->get_variable('SL_DATE', true);
    $zone = StatusLookupConf::timezone()->getName();
    if (preg_match('/^[0-9]{1,12}$/', $epoch) === 1 && preg_match('/^[A-Za-z0-9_\/+-]{1,64}$/', $zone) === 1) {
        // Weekday, month and day ("Thursday, November twelfth"), without the year.
        $agi->evaluate("SAY DATETIME $epoch \"\" ABd $zone");
    }
    exit(0);
}

if ($mode !== 'lookup') {
    exit(0);
}

try {
    $db = Databases::openReadOnly(StatusLookupConf::moduleDbFile());
    $settings = Settings::load($db);
    $lookup = new Lookup($db, $settings, StatusLookupConf::timezone(), [StatusLookupConf::class, 'promptPath']);
    $vars = $lookup->find(
        (string)$agi->get_variable('SL_IN', true),
        (string)$agi->get_variable('SL_CALLER', true),
        (int)$agi->get_variable('SL_MISSES', true),
        time(),
    );
} catch (Throwable $e) {
    SystemMessages::sysLogMsg('StatusLookup', 'Lookup failed: ' . $e->getMessage(), LOG_ERR);
    $vars = ['SL_RESULT' => 'fault', 'SL_ID' => '', 'SL_STATUS' => '', 'SL_PROMPT' => '', 'SL_EXTRA' => 'none',
        'SL_DATE' => '', 'SL_ACTION' => '', 'SL_TRANSFER' => ''];
}
foreach ($vars as $name => $value) {
    $agi->set_variable($name, $value);
}
