<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use DateTimeZone;
use InvalidArgumentException;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\Workers\WorkerBase;
use Throwable;

require_once 'Globals.php';

/**
 * The module's worker. The supervisor starts it as its own PHP process and
 * checks it over Beanstalk (CHECK_BY_BEANSTALK).
 *
 * Each call the hand-off AGI script queues is validated again (Handoff) and
 * written to the call log and daily counts (CallLogStore). This worker is
 * their only writer.
 *
 * The logic lives in plain, unit-tested classes; this class only wires them
 * to the PBX.
 */
class WorkerStatusLookup extends WorkerBase
{
    private CallLogStore $store;

    public function start(array $argv): void
    {
        $this->store = new CallLogStore(Databases::openWritable(StatusLookupConf::moduleDbFile()));

        $client = new BeanstalkClient(self::class);
        $client->subscribe(self::class, [$this, 'onRecord']);
        $client->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);

        while ($this->needRestart === false) {
            $client->wait(1);
        }
    }

    /** One finished call from the hand-off AGI script. */
    public function onRecord(BeanstalkClient $message): void
    {
        try {
            $record = Handoff::decode($message->getBody());
            $this->store->record($record, $this->timezone());
        } catch (InvalidArgumentException $e) {
            SystemMessages::sysLogMsg(static::class, 'Call record rejected: ' . $e->getMessage(), LOG_WARNING);
        } catch (Throwable $e) {
            // Logged, not rethrown: a rethrow would bury the job and stop nothing.
            SystemMessages::sysLogMsg(static::class, 'Storing a call record failed: ' . $e->getMessage(), LOG_ERR);
        }
    }

    /**
     * The PBX's time zone, read afresh: a long-running worker keeps the zone
     * it started with.
     */
    private function timezone(): DateTimeZone
    {
        try {
            $core = Databases::openReadOnly((string)$this->di->getShared('config')->path('database.dbfile'));

            return new DateTimeZone(Databases::coreSetting($core, 'PBXTimezone', date_default_timezone_get()));
        } catch (Throwable) {
            return new DateTimeZone(date_default_timezone_get());
        }
    }
}

// Start the worker when this file is run by the supervisor.
WorkerStatusLookup::startWorker($argv ?? []);
