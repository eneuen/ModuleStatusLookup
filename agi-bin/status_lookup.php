#!/usr/bin/php
<?php

// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * One step of a call to the lookup line, run by its dialplan:
 *
 *   lookup  looks up what the caller typed (SL_IN) and sets the SL_*
 *           variables the dialplan acts on (Lookup). On any error it sets
 *           SL_RESULT=fault, which sends the caller to a person.
 *   pin     for a status that asks for the record's PIN: plays the prompt
 *           given as the second argument, collects the PIN with GET DATA
 *           (so it never reaches a channel variable, as with the dialplan's
 *           Read()), and looks up again with it. 0 alone sets SL_PIN0, and
 *           the dialplan puts the caller through to a person. (The PBX's
 *           Asterisk log records every key pressed on any call, a PBX-wide
 *           setting the module can't change.)
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
use Modules\ModuleStatusLookup\Lib\Pin;
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

if ($mode !== 'lookup' && $mode !== 'pin') {
    exit(0);
}

$typedPin = null;
if ($mode === 'pin') {
    // The prompt and the wait come from the dialplan, which checked them; checked again here.
    $prompt = (string)($argv[2] ?? '');
    $seconds = max(1, min(30, (int)($argv[3] ?? 5)));
    if (preg_match('/^(modulestatuslookup-[a-z-]{1,20}|\/[A-Za-z0-9_\/.-]{1,250})$/', $prompt) !== 1) {
        exit(0);
    }
    $typed = $agi->getData($prompt, $seconds * 1000, 8);
    $typedPin = (string)($typed['result'] ?? '');
    if ((int)($typed['code'] ?? 0) !== 200 || $typedPin === '-1') {
        exit(0);   // the caller hung up; SL_RESULT stays fault, and the hangup handler logs the call
    }
    if ($typedPin === '0') {
        $agi->set_variable('SL_RESULT', 'need_pin');
        $agi->set_variable('SL_PIN0', '1');
        exit(0);
    }
}

try {
    $db = Databases::openReadOnly(StatusLookupConf::moduleDbFile());
    $settings = Settings::load($db);
    // Without a usable key, only statuses that ask for a PIN are affected: their callers go to a person (no_pin).
    try {
        $pin = Pin::fromFile(StatusLookupConf::pinKeyFile());
    } catch (Throwable $e) {
        SystemMessages::sysLogMsg('StatusLookup', 'PIN key: ' . $e->getMessage(), LOG_ERR);
        $pin = null;
    }
    $lookup = new Lookup($db, $settings, StatusLookupConf::timezone(), [StatusLookupConf::class, 'promptPath'], $pin);
    $input = (string)$agi->get_variable('SL_IN', true);
    $vars = $lookup->find(
        $input,
        (string)$agi->get_variable('SL_CALLER', true),
        (int)$agi->get_variable('SL_MISSES', true),
        time(),
        $typedPin,
    );
    // A wrong PIN counts against the ID at once, for every call (the lookup itself only reads).
    if ($mode === 'pin' && $vars['SL_RESULT'] === 'pin_failed') {
        Lookup::recordPinFail(Databases::openWritable(StatusLookupConf::moduleDbFile()), $input, time());
    }
} catch (Throwable $e) {
    SystemMessages::sysLogMsg('StatusLookup', 'Lookup failed: ' . $e->getMessage(), LOG_ERR);
    $vars = ['SL_RESULT' => 'fault', 'SL_ID' => '', 'SL_STATUS' => '', 'SL_PROMPT' => '', 'SL_EXTRA' => 'none',
        'SL_DATE' => '', 'SL_ACTION' => '', 'SL_TRANSFER' => ''];
}
foreach ($vars as $name => $value) {
    $agi->set_variable($name, $value);
}
