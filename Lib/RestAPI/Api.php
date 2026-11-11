<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\Lib\RestAPI;

use MikoPBX\Core\System\SystemMessages;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleStatusLookup\Lib\Databases;
use Modules\ModuleStatusLookup\Lib\EditorException;
use Modules\ModuleStatusLookup\Lib\Pin;
use Modules\ModuleStatusLookup\Lib\RecordsApi;
use Modules\ModuleStatusLookup\Lib\StatusLookupConf;
use Phalcon\Di\Di;
use PDOException;
use Throwable;

/**
 * What the REST processors share: the request's data and caller, the API
 * logic (RecordsApi), and turning its result or refusal into the core's
 * PBXApiResult.
 *
 * The processors run in the core's API worker (as root), not in the web
 * server: everything about the request comes from the queued envelope.
 * Who may call is the core's business (API key scopes, administrators'
 * logins, and scripts on the PBX itself); other roles are refused here too.
 */
final class Api
{
    /** Error codes that mean "there is no such record", and those that mean "not allowed". */
    private const array NOT_FOUND = ['not_found'];

    private const array FORBIDDEN = ['forbidden'];

    /**
     * Runs one API call and wraps its result.
     *
     * @param array<string, mixed> $request The core's queue envelope.
     * @param callable(RecordsApi, array<string, mixed>, string): (PBXApiResult|array<string, mixed>) $action
     *        Gets the API, the request's data (path id as 'id') and the caller's name.
     */
    public static function run(array $request, string $processor, callable $action): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->processor = $processor;
        $result->function = (string)($request['action'] ?? '');
        try {
            $context = is_array($request['sessionContext'] ?? null) ? $request['sessionContext'] : [];
            // Administrators and API keys only; other roles (from user-management modules) are refused.
            if (is_string($context['role'] ?? null) && $context['role'] !== 'admins') {
                throw new EditorException('forbidden');
            }
            $data = is_array($request['data'] ?? null) ? $request['data'] : [];
            unset($data['_url'], $data['_idFromRequestBody'], $data['httpMethod']);
            $db = Databases::openWritable(StatusLookupConf::moduleDbFile());
            $api = new RecordsApi($db, StatusLookupConf::timezone(), Pin::fromFile(StatusLookupConf::pinKeyFile()));
            $out = $action($api, $data, self::caller($context));
            if ($out instanceof PBXApiResult) {
                $out->processor = $result->processor;
                $out->function = $result->function;
                return $out;
            }
            $result->success = true;
            $result->data = $out;
        } catch (EditorException $e) {
            $result->messages['error'][] = $e->errorCode;
            $result->data = ['error' => $e->errorCode, 'params' => $e->params];
            $result->httpCode = in_array($e->errorCode, self::NOT_FOUND, true) ? 404
                : (in_array($e->errorCode, self::FORBIDDEN, true) ? 403 : 400);
        } catch (PDOException $e) {
            SystemMessages::sysLogMsg(self::class, 'Database error: ' . $e->getMessage(), LOG_ERR);
            $result->messages['error'][] = 'failed';
            $result->httpCode = 500;
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(self::class, 'Request failed: ' . $e->getMessage(), LOG_ERR);
            $result->messages['error'][] = 'failed';
            $result->httpCode = 500;
        }

        return $result;
    }

    /** The record ID from the path; IDs are digits, kept as text so leading zeros count. */
    public static function id(array $data): string
    {
        $id = is_scalar($data['id'] ?? null) ? (string)$data['id'] : '';
        if (preg_match('/^[0-9]{1,20}\z/', $id) !== 1) {
            throw new EditorException('not_found');
        }

        return $id;
    }

    /** A success with no body other than $data, and another HTTP status (201 Created, for one). */
    public static function status(int $httpCode, array $data): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = true;
        $result->data = $data;
        $result->httpCode = $httpCode;

        return $result;
    }

    /** A refusal for a call the resource doesn't offer (the core also routes, say, POST /records here). */
    public static function notAllowed(): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->messages['error'][] = 'method_not_allowed';
        $result->httpCode = 405;

        return $result;
    }

    /**
     * Who made the change, for "updated by": the API key's description, the
     * administrator's login, or "PBX" for a script on the PBX itself.
     *
     * @param array<string, mixed> $context
     */
    private static function caller(array $context): string
    {
        if (is_string($context['user_name'] ?? null) && $context['user_name'] !== '') {
            return $context['user_name'];
        }
        if (is_numeric($context['token_id'] ?? null)) {
            try {
                $core = Databases::openReadOnly((string)Di::getDefault()?->getShared('config')->path('database.dbfile'));
                $stmt = $core->prepare('SELECT description FROM m_ApiKeys WHERE id = ?');
                $stmt->execute([(int)$context['token_id']]);
                $name = trim((string)$stmt->fetchColumn());
                return 'API key: ' . ($name !== '' ? $name : '#' . (int)$context['token_id']);
            } catch (Throwable) {
                return 'API key #' . (int)$context['token_id'];
            }
        }

        return 'PBX';
    }
}
