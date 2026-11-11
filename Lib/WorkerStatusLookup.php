<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib;

use InvalidArgumentException;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\System\Util;
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
 * Requests from the module's page arrive on StatusLookupConf::COMMANDS_TUBE:
 * a test call, which needs the AMI connection the web process doesn't have.
 *
 * Every night (and a minute after the worker starts) it deletes calls past
 * the retention period and, when that is switched on, expired records
 * (Retention).
 *
 * The logic lives in plain, unit-tested classes; this class only wires them
 * to the PBX.
 */
class WorkerStatusLookup extends WorkerBase
{
    /** Seconds a test call request may wait in the queue. */
    private const int TEST_CALL_MAX_AGE = 30;

    /** The nightly clean-up runs at this hour, PBX time. */
    private const int MAINTENANCE_HOUR = 3;

    private CallLogStore $store;

    private int $nextMaintenance = 0;

    public function start(array $argv): void
    {
        $this->store = new CallLogStore(Databases::openWritable(StatusLookupConf::moduleDbFile()));

        $client = new BeanstalkClient(self::class);
        $client->subscribe(self::class, [$this, 'onRecord']);
        $client->subscribe(StatusLookupConf::COMMANDS_TUBE, [$this, 'onCommand']);
        $client->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);

        // Not at once: the supervisor restarts workers in bursts, and a restart shouldn't wait for the clean-up.
        $this->nextMaintenance = time() + 60;
        while ($this->needRestart === false) {
            $client->wait(1);
            if (time() >= $this->nextMaintenance) {
                $this->maintenance();
            }
        }
    }

    /** The nightly clean-up; the next one is scheduled whatever happens, so a failure isn't retried every second. */
    private function maintenance(): void
    {
        $this->nextMaintenance = time() + 86400;
        try {
            $timezone = StatusLookupConf::timezone();
            $next = (new \DateTimeImmutable('now', $timezone))->setTime(self::MAINTENANCE_HOUR, 0);
            $this->nextMaintenance = ($next->getTimestamp() > time() ? $next : $next->modify('+1 day'))->getTimestamp();
            $db = Databases::openWritable(StatusLookupConf::moduleDbFile());
            $settings = Settings::load($db);
            $deleted = (new Retention($db, $timezone))->run(time(),
                Retention::logDays((int)$settings['log_retention_days'], StatusLookupConf::cdrDays()), $settings['purge_expired'] === '1');
            if ($deleted['calls'] > 0 || $deleted['records'] > 0) {
                SystemMessages::sysLogMsg(static::class, "Clean-up: deleted {$deleted['calls']} old calls and {$deleted['records']} expired records", LOG_INFO);
            }
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Clean-up failed: ' . $e->getMessage(), LOG_ERR);
        }
    }

    /** One finished call from the hand-off AGI script. */
    public function onRecord(BeanstalkClient $message): void
    {
        try {
            $record = Handoff::decode($message->getBody());
            $this->store->record($record, StatusLookupConf::timezone());
        } catch (InvalidArgumentException $e) {
            SystemMessages::sysLogMsg(static::class, 'Call record rejected: ' . $e->getMessage(), LOG_WARNING);
        } catch (Throwable $e) {
            // Logged, not rethrown: a rethrow would bury the job and stop nothing.
            SystemMessages::sysLogMsg(static::class, 'Storing a call record failed: ' . $e->getMessage(), LOG_ERR);
        }
    }

    /**
     * A request from the page. The page has checked it; it is checked again
     * here, because anything that can publish to the queue reaches this.
     */
    public function onCommand(BeanstalkClient $message): void
    {
        try {
            $request = json_decode($message->getBody(), true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($request) || ($request['command'] ?? '') !== StatusLookupConf::COMMAND_TEST_CALL) {
                throw new InvalidArgumentException('Unknown command');
            }
            // A request that waited (the worker was restarting) is dropped: a phone ringing much later would surprise.
            if (time() - (int)($request['requested_at'] ?? 0) > self::TEST_CALL_MAX_AGE) {
                throw new InvalidArgumentException('Test call request expired');
            }
            $this->testCall((string)($request['extension'] ?? ''));
        } catch (InvalidArgumentException | \JsonException $e) {
            SystemMessages::sysLogMsg(static::class, 'Command rejected: ' . $e->getMessage(), LOG_WARNING);
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Command failed: ' . $e->getMessage(), LOG_ERR);
        }
    }

    /**
     * Rings an employee's extension; once answered, that call enters the
     * lookup line's number in [internal], marked as a test (SL_TEST), so it
     * is logged but left out of the numbers and the miss limits.
     */
    private function testCall(string $extension): void
    {
        $core = Databases::openReadOnly((string)$this->di->getShared('config')->path('database.dbfile'));
        if (!in_array($extension, array_column(PbxOptions::extensions($core), 'number'), true)) {
            throw new InvalidArgumentException('Unknown extension');
        }
        $number = (string)Settings::load(Databases::openReadOnly(StatusLookupConf::moduleDbFile()))['internal_number'];
        if (!IvrNumber::isValid($number)) {
            throw new InvalidArgumentException('The line has no number');
        }
        $result = Util::getAstManager('off')->Originate(
            "Local/$extension@internal",
            $number,
            'internal',
            1,
            null,
            null,
            30000,
            // The phone shows who is calling; the call log shows the tester's extension as the caller.
            '"' . CoreNumber::LABEL . '" <' . $extension . '>',
            'SL_TEST=1',
        );
        if (strcasecmp((string)($result['Response'] ?? ''), 'Success') !== 0) {
            throw new \RuntimeException('Originate refused: ' . ($result['Message'] ?? 'no response'));
        }
    }
}

// Start the worker when this file is run by the supervisor.
WorkerStatusLookup::startWorker($argv ?? []);
