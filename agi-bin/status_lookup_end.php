#!/usr/bin/php
<?php

// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * End-of-call hand-off, once per call to the lookup line: run by its hangup
 * handler, or just before the caller is sent on to a person.
 *
 * It reads the call's SL_* channel variables, validates every value against
 * a fixed pattern (Handoff) and publishes the record to the module worker's
 * Beanstalk queue. Nothing else: no shell, no files, no network beyond the
 * local queue. Invalid records are logged and dropped.
 */

declare(strict_types=1);

use MikoPBX\Core\Asterisk\AGI;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\SystemMessages;
use Modules\ModuleStatusLookup\Lib\Handoff;
use Modules\ModuleStatusLookup\Lib\WorkerStatusLookup;

require_once 'Globals.php';

set_time_limit(5);

try {
    $agi = new AGI();
    $vars = [];
    foreach (Handoff::channelVariables() as $name) {
        $vars[$name] = (string)$agi->get_variable($name, true);
    }
    $record = Handoff::fromChannel((string)$agi->get_variable('CHANNEL(linkedid)', true), $vars, time());
    (new BeanstalkClient(WorkerStatusLookup::class))->publish(Handoff::encode($record));
} catch (InvalidArgumentException $e) {
    SystemMessages::sysLogMsg('StatusLookup', 'Call record rejected: ' . $e->getMessage(), LOG_WARNING);
} catch (Throwable $e) {
    SystemMessages::sysLogMsg('StatusLookup', 'Call hand-off failed: ' . $e->getMessage(), LOG_ERR);
}
