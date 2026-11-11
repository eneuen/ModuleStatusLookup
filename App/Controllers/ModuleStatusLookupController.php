<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleStatusLookup\App\Controllers;

use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\Common\Providers\JwtProvider;
use MikoPBX\Core\System\SystemMessages;
use Modules\ModuleStatusLookup\Lib\AccessPolicy;
use Modules\ModuleStatusLookup\Lib\Databases;
use Modules\ModuleStatusLookup\Lib\IvrNumber;
use Modules\ModuleStatusLookup\Lib\Settings;
use Modules\ModuleStatusLookup\Lib\StatusLookupConf;
use Phalcon\Mvc\Dispatcher;
use Throwable;

/**
 * Admin-cabinet page for Status Lookup.
 *
 * Every request is checked for the admins role here, on top of the core's
 * SecurityPlugin: another module can grant other roles access to any
 * controller. The core's beforeExecuteRoute() returns void, so a refused
 * request is forwarded to deniedAction() rather than stopped.
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

    /** The module page: for now, the IVR's number. */
    public function indexAction(): void
    {
        $number = '';
        try {
            $number = (string)Settings::load(Databases::openReadOnly(StatusLookupConf::moduleDbFile()))['internal_number'];
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(self::class, 'Settings not read: ' . $e->getMessage(), LOG_ERR);
        }
        $this->view->setVar('number', IvrNumber::isValid($number) ? $number : '');
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
