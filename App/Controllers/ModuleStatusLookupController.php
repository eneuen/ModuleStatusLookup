<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\App\Controllers;

use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\AdminCabinet\Providers\AssetProvider;
use MikoPBX\Common\Providers\JwtProvider;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\Workers\Libs\WorkerModelsEvents\Actions\ReloadDialplanAction;
use MikoPBX\Core\Workers\WorkerModelsEvents;
use Modules\ModuleStatusLookup\Lib\AccessPolicy;
use Modules\ModuleStatusLookup\Lib\BuiltInPrompts;
use Modules\ModuleStatusLookup\Lib\CallFlowEditor;
use Modules\ModuleStatusLookup\Lib\CoreNumber;
use Modules\ModuleStatusLookup\Lib\Databases;
use Modules\ModuleStatusLookup\Lib\DialplanBuilder;
use Modules\ModuleStatusLookup\Lib\EditorException;
use Modules\ModuleStatusLookup\Lib\IvrNumber;
use Modules\ModuleStatusLookup\Lib\PbxOptions;
use Modules\ModuleStatusLookup\Lib\Settings;
use Modules\ModuleStatusLookup\Lib\StatusLookupConf;
use PDO;
use Phalcon\Mvc\Dispatcher;
use RuntimeException;
use Throwable;

/**
 * Admin-cabinet page and JSON actions for Status Lookup.
 *
 * Every request is checked for the admins role here, on top of the core's
 * SecurityPlugin: another module can grant other roles access to any
 * controller. The core's beforeExecuteRoute() returns void, so a refused
 * request is forwarded to deniedAction() rather than stopped.
 *
 * Read actions are AJAX GETs over read-only connections. Write actions go
 * through write(): AJAX POST with a Bearer token, so the login cookie alone,
 * as a cross-site form would send it, is refused. Validation is in the Lib
 * classes; a refused change comes back as a message code the page shows in
 * the admin's language. Names from the PBX are returned as data; the page
 * renders them as text only.
 */
class ModuleStatusLookupController extends BaseController
{
    private const string DENIED_ACTION = 'denied';

    public function beforeExecuteRoute(Dispatcher $dispatcher): void
    {
        parent::beforeExecuteRoute($dispatcher);

        if ($dispatcher->getActionName() === self::DENIED_ACTION) {
            return;
        }
        if (!AccessPolicy::isAllowedRole($this->currentRole())) {
            $dispatcher->forward(['action' => self::DENIED_ACTION]);
        }
    }

    /** The module page. No data goes through Volt; the page loads it as JSON. */
    public function indexAction(): void
    {
        $this->assets->collection(AssetProvider::SEMANTIC_UI_CSS)
            ->addCss('css/vendor/semantic/modal.min.css', true);
        $this->assets->collection(AssetProvider::SEMANTIC_UI_JS)
            ->addJs('js/vendor/semantic/modal.min.js', true);
        $this->assets->collection(AssetProvider::HEADER_CSS)
            ->addCss('css/cache/' . StatusLookupConf::MODULE_ID . '/status-lookup.css', true);
        $this->assets->collection(AssetProvider::FOOTER_JS)
            ->addJs('js/cache/' . StatusLookupConf::MODULE_ID . '/status-lookup.js', true);
    }

    /** GET options: what the forms pick from. */
    public function optionsAction(): void
    {
        $this->json(function (): array {
            $core = $this->coreDb();
            $settings = Settings::load($this->moduleDb());
            $moduleDir = dirname(StatusLookupConf::moduleDbFile(), 2);
            $language = StatusLookupConf::language();
            $builtIn = [];
            foreach (BuiltInPrompts::catalogue(Directories::getDir(Directories::AST_SOUNDS_DIR)) as $prompt) {
                $builtIn[] = $prompt + ['available' => BuiltInPrompts::available($moduleDir, $prompt['id'], $language)];
            }

            return [
                'soundFiles'     => PbxOptions::soundFiles($core),
                'builtInPrompts' => $builtIn,
                'destinations'   => PbxOptions::destinations($core, (string)$settings['internal_number']),
                'language'       => BuiltInPrompts::languageFor($language),
            ];
        });
    }

    /** GET callFlow: the settings, what stops the line taking calls, and what sends callers to it. */
    public function callFlowAction(): void
    {
        $this->json(fn (): array => $this->callFlow());
    }

    /** POST saveCallFlow: settings (JSON object). */
    public function saveCallFlowAction(): void
    {
        $this->write(function (): array {
            $db = $this->writableModuleDb();
            $values = $this->editor()->change($this->jsonPost('settings'), Settings::load($db));
            Settings::save($db, $values);

            return $this->callFlow();
        }, true);
    }

    /**
     * POST changeNumber: number. Registers the new number, then releases the
     * old one. The core refuses to release a number something still sends
     * callers to; then nothing changes and the page lists what uses it.
     */
    public function changeNumberAction(): void
    {
        $this->write(function (): array {
            $number = trim((string)$this->request->getPost('number'));
            if (!IvrNumber::isValid($number)) {
                throw new EditorException('number_format');
            }
            $db = $this->writableModuleDb();
            $old = (string)Settings::load($db)['internal_number'];
            if ($number === $old) {
                return $this->callFlow();
            }
            if (CoreNumber::isTaken($number)) {
                throw new EditorException('number_taken', ['number' => $number]);
            }
            CoreNumber::register($number);
            Settings::save($db, ['internal_number' => $number]);
            if ($old !== '' && CoreNumber::unregister($old) !== []) {
                // Put everything back: the old number stays the line's.
                Settings::save($db, ['internal_number' => $old]);
                CoreNumber::unregister($number);
                throw new EditorException('number_in_use', ['number' => $old]);
            }

            return $this->callFlow();
        }, true);
    }

    /** 403 for anyone who is not an admin: JSON for AJAX, a page otherwise. */
    public function deniedAction(): void
    {
        $this->response->setStatusCode(403, 'Forbidden');
        if ($this->request->isAjax()) {
            $this->view->setVar('raw_response', json_encode([
                'success' => false,
                'reload'  => false,
                'message' => ['error' => $this->translation->_('sl_AccessDenied')],
            ]));
        }
    }

    /** @return array<string, mixed> */
    private function callFlow(): array
    {
        $settings = Settings::load($this->moduleDb());
        $number = (string)$settings['internal_number'];
        $problems = $this->editor()->problems($settings);
        if ($number !== '' && !CoreNumber::isRegistered($number)) {
            $problems[] = 'not_registered';
        }

        return [
            'settings'   => $settings,
            'problems'   => $problems,
            // On means the line answers: switched on and nothing missing.
            'answering'  => $settings['active'] === '1' && $problems === [],
            'usedBy'     => PbxOptions::numberUsers($this->coreDb(), $number),
            'maxSeconds' => DialplanBuilder::MAX_SECONDS,
        ];
    }

    private function editor(): CallFlowEditor
    {
        $moduleDir = dirname(StatusLookupConf::moduleDbFile(), 2);
        $language = StatusLookupConf::language();
        $usable = static fn (int $id): bool => BuiltInPrompts::isBuiltIn($id)
            ? BuiltInPrompts::available($moduleDir, $id, $language)
            : $id > 0 && StatusLookupConf::promptPath($id) !== null;
        $number = (string)Settings::load($this->moduleDb())['internal_number'];

        return new CallFlowEditor($usable, array_column(PbxOptions::destinations($this->coreDb(), $number), 'value'));
    }

    /**
     * Runs $build for a read action (AJAX GET) and sends its result as JSON.
     *
     * @param callable(): array<string, mixed> $build
     */
    private function json(callable $build): void
    {
        if (!$this->request->isGet() || !$this->request->isAjax()) {
            $this->respond(400, ['success' => false, 'message' => 'bad_request']);
            return;
        }
        $this->run($build);
    }

    /**
     * Runs $change for a write action, after the write rules: AJAX POST with a
     * Bearer token. With $reload, the dialplan is regenerated afterwards.
     *
     * @param callable(): array<string, mixed> $change
     */
    private function write(callable $change, bool $reload = false): void
    {
        $auth = $this->request->getHeader('Authorization');
        if (!AccessPolicy::isAllowedWrite($this->request->isPost(), $this->request->isAjax(), $auth === '' ? null : $auth)) {
            $this->respond(403, ['success' => false, 'message' => 'bad_request']);
            return;
        }
        if ($this->run($change) && $reload) {
            WorkerModelsEvents::invokeAction(ReloadDialplanAction::class);
        }
    }

    /** @param callable(): array<string, mixed> $action */
    private function run(callable $action): bool
    {
        try {
            $this->respond(200, ['success' => true, 'data' => $action()]);

            return true;
        } catch (EditorException $e) {
            $this->respond(400, ['success' => false, 'message' => $e->errorCode, 'params' => $e->params]);
        } catch (RuntimeException $e) {
            SystemMessages::sysLogMsg(self::class, 'Change refused: ' . $e->getMessage(), LOG_WARNING);
            $this->respond(400, ['success' => false, 'message' => 'refused']);
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(self::class, 'Request failed: ' . $e->getMessage(), LOG_ERR);
            $this->respond(500, ['success' => false, 'message' => 'failed']);
        }

        return false;
    }

    /** @param array<string, mixed> $body */
    private function respond(int $status, array $body): void
    {
        $this->response->setStatusCode($status);
        $this->view->setVar('raw_response', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * A JSON-encoded POST field, decoded; nested values arrive intact this way.
     *
     * @return array<string, mixed>
     */
    private function jsonPost(string $name): array
    {
        $raw = $this->request->getPost($name);
        if (!is_string($raw) || strlen($raw) > 65536) {
            throw new EditorException('bad_request');
        }
        try {
            $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new EditorException('bad_request');
        }
        if (!is_array($value)) {
            throw new EditorException('bad_request');
        }

        return $value;
    }

    private function moduleDb(): PDO
    {
        return Databases::openReadOnly(StatusLookupConf::moduleDbFile());
    }

    private function writableModuleDb(): PDO
    {
        return Databases::openWritable(StatusLookupConf::moduleDbFile());
    }

    private function coreDb(): PDO
    {
        return Databases::openReadOnly((string)$this->di->getShared('config')->path('database.dbfile'));
    }

    /**
     * The caller's role, resolved the same way as the core's SecurityPlugin:
     * the Bearer token first, then the refresh-token cookie of a page load.
     */
    private function currentRole(): ?string
    {
        $jwt = $this->di->getShared(JwtProvider::SERVICE_NAME);

        $role = $jwt->extractRoleFromHeader($this->request->getHeader('Authorization'));
        if ($role !== null) {
            return $role;
        }

        if ($this->cookies->has('refreshToken')) {
            try {
                $refreshToken = $this->cookies->get('refreshToken')->getValue();
                if (!empty($refreshToken)) {
                    return $jwt->extractRoleFromRefreshToken($refreshToken);
                }
            } catch (Throwable) {
                // An unreadable cookie counts as no role: refused.
            }
        }

        return null;
    }
}
