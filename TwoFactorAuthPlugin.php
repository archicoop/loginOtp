<?php

namespace APP\plugins\generic\twoFactorAuth;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\twoFactorAuth\classes\TwoFactorAuthDAO;
use APP\plugins\generic\twoFactorAuth\classes\TwoFactorAuthSettingsForm;
use Illuminate\Support\Facades\DB;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Validation;

class TwoFactorAuthPlugin extends GenericPlugin
{
    public function getDisplayName(): string
    {
        return __('plugins.generic.twoFactorAuth.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.twoFactorAuth.description');
    }

    public function register($category, $path, $mainContextId = null): bool
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }
        if ($this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->handleLoadHandler(...));
        }
        return true;
    }

    // ─── Plugin settings ──────────────────────────────────────────────────────

    public function getActions($request, $actionArgs): array
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }
        $url = $request->getRouter()->url(
            $request,
            null,
            null,
            'manage',
            null,
            ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']
        );
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal($url, $this->getDisplayName()),
            __('manager.plugins.settings'),
            null
        ));
        return $actions;
    }

    public function manage($args, $request): JSONMessage
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $contextId = (int) ($request->getContext()?->getId() ?? 0);
                $form = new TwoFactorAuthSettingsForm($this, $contextId);
                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        return new JSONMessage(true);
                    }
                } else {
                    $form->initData();
                }
                return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }

    // ─── Login hook ───────────────────────────────────────────────────────────

    public function handleLoadHandler(string $hookName, array $args): bool
    {
        [&$page, &$op, , &$handler] = $args;

        if ($page === 'twoFactorAuth') {
            $handler = new TwoFactorAuthHandler($this);
            return Hook::ABORT;
        }

        if ($page === 'login' && $op === 'signIn') {
            $request = Application::get()->getRequest();
            if (!$request->isPost()) {
                return Hook::CONTINUE;
            }
            return $this->interceptSignIn($request);
        }

        return Hook::CONTINUE;
    }

    private function interceptSignIn($request): bool
    {
        if (!$request->checkCSRF()) {
            return Hook::CONTINUE;
        }

        $username = trim((string) $request->getUserVar('username'));
        if ($username === '') {
            return Hook::CONTINUE;
        }

        // Resolve user by username or email (both OMP and OJS accept both)
        $user = Repo::user()->getByUsername($username, true)
            ?? Repo::user()->getByEmail($username, true);

        // Unknown or disabled user → let LoginHandler show its error
        if (!$user || $user->getDisabled()) {
            return Hook::CONTINUE;
        }

        // Validate password without opening a session
        $password = (string) $request->getUserVar('password');
        $rehash   = null;
        if (!Validation::verifyPassword($user->getUsername(), $password, $user->getPassword(), $rehash)) {
            return Hook::CONTINUE;
        }

        if (!empty($rehash)) {
            $user->setPassword($rehash);
            Repo::user()->edit($user);
        }

        // Check if user's role requires 2FA
        if (!$this->userRequires2FA($user->getId(), $request)) {
            return Hook::CONTINUE;
        }

        $dao = new TwoFactorAuthDAO();

        // Throttle: send at most one OTP per 60 seconds
        if ($dao->isOtpThrottled($user->getId())) {
            $request->redirect(null, 'twoFactorAuth', 'verify');
            return Hook::ABORT;
        }

        // Generate OTP and store its hash in session (never the plain code)
        $code     = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = hash('sha256', $code);

        $session = $request->getSession();
        $session->put('2fa_pending_user_id', $user->getId());
        $session->put('2fa_pending_expires', time() + 600);    // 10 minutes
        $session->put('2fa_pending_code',    $codeHash);
        $session->put('2fa_source',          (string) $request->getUserVar('source'));
        $session->put('2fa_remember',        (bool)   $request->getUserVar('remember'));

        $dao->recordOtpSent($user->getId());
        TwoFactorAuthHandler::sendOtpEmail($user, $code, $request);

        $request->redirect(null, 'twoFactorAuth', 'verify');
        return Hook::ABORT;
    }

    /**
     * Returns true if the user has at least one role that requires 2FA.
     * Null setting = not configured → apply to all (backward compatible default).
     */
    private function userRequires2FA(int $userId, $request): bool
    {
        $contextId     = (int) ($request->getContext()?->getId() ?? 0);
        $requiredRoles = $this->getSetting($contextId, 'requiredRoles');

        // Not configured yet → require 2FA for everyone
        if ($requiredRoles === null) {
            return true;
        }

        // Explicitly empty → no one needs 2FA
        if (empty($requiredRoles)) {
            return false;
        }

        // Get all role IDs assigned to the user (across all contexts)
        $userRoleIds = DB::table('user_groups as ug')
            ->join('user_user_groups as uug', 'ug.user_group_id', '=', 'uug.user_group_id')
            ->where('uug.user_id', $userId)
            ->pluck('ug.role_id')
            ->unique()
            ->toArray();

        return !empty(array_intersect($requiredRoles, $userRoleIds));
    }
}
