<?php

namespace APP\plugins\generic\loginOtp;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\loginOtp\classes\LoginOtpDAO;
use APP\plugins\generic\loginOtp\classes\LoginOtpSettingsForm;
use Illuminate\Support\Facades\DB;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Validation;
use PKP\security\Role;


class LoginOtpPlugin extends GenericPlugin
{
    public function getDisplayName(): string
    {
        return __('plugins.generic.loginOtp.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.loginOtp.description');
    }

    public function isSitePlugin(): bool
    {
        return true;
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
                $contextId = 0; // 0, forced by isSitePlugin()
                $form = new LoginOtpSettingsForm($this, $contextId);
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

        if ($page === 'loginOtp') {
            $handler = new LoginOtpHandler($this);
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

        $username = trim((string)$request->getUserVar('username'));
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
        $password = (string)$request->getUserVar('password');
        $rehash = null;
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

        $dao = new LoginOtpDAO();
        $session = $request->getSession();

        $pendingCode = $session->get('2fa_pending_code');
        $pendingExpires = (int) $session->get('2fa_pending_expires');
        $hasValidPendingCode = $pendingCode && time() < $pendingExpires;

        if ($dao->isOtpThrottled($user->getId()) && $hasValidPendingCode) {
            $request->redirect(null, 'loginOtp', 'verify');
            return Hook::ABORT;
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = hash('sha256', $code);

        $session->put('2fa_pending_user_id', $user->getId());
        $session->put('2fa_pending_expires', time() + 600);    // 10 minutes
        $session->put('2fa_pending_code', $codeHash);
        $session->put('2fa_source', (string)$request->getUserVar('source'));
        $session->put('2fa_remember', (bool)$request->getUserVar('remember'));

        $dao->recordOtpSent($user->getId());
        LoginOtpHandler::sendOtpEmail($user, $code, $request);

        $request->redirect(null, 'loginOtp', 'verify');
        return Hook::ABORT;
    }

    /**
     * Determina se il login richiede la 2FA.
     *
     * Regole, in ordine di precedenza:
     *  1. Login a livello sito (nessuna rivista) → sempre 2FA.
     *  2. Utente con ruolo Site Administrator → sempre 2FA.
     *  3. Impostazione non configurata per la rivista → default sicuro: 2FA.
     *  4. Logica OR: se almeno un ruolo dell'utente in questa rivista è tra
     *     quelli soggetti a 2FA → 2FA.
     *
     * NOTA: l'eventuale "floor" sul ruolo Journal Manager (sempre 2FA, non
     * disattivabile dal manager) è in attesa di conferma dal PM. Andrebbe
     * inserito come Regola 2-bis, prima della logica OR.
     */
    private function userRequires2FA(int $userId, $request): bool
    {
        $context = $request->getContext();

        // Regola 1: login di sito → sempre 2FA
        if ($context === null || (int)$context->getId() === 0) {
            return true;
        }
        $contextId = (int)$context->getId();

        // Regola 2: Site Admin → sempre 2FA (ruolo non legato a una rivista)
        if ($this->userHasRole($userId, Role::ROLE_ID_SITE_ADMIN)) {
            return true;
        }

        // Regola 2-bis (in attesa di conferma PM): Journal Manager sempre 2FA
        if (in_array(Role::ROLE_ID_MANAGER, $this->getUserRoleIdsInContext($userId, $contextId))) {
            return true;
        }

        // Regola 3: non configurato per questa rivista → default sicuro
/*        $requiredRoles = $this->getSetting($contextId, 'requiredRoles');
        if ($requiredRoles === null) {
            return true;
        }*/

        // Regola 3: non configurato → default sicuro
        // TODO: temporaneo (lettura da site_settings, allineata al form).
        // Quando arriverà la modifica 2 (settings per-rivista), tornare a
        // leggere via $this->getSetting($contextId, 'requiredRoles').
        $row = DB::table('site_settings')
            ->where('setting_name', 'loginOtp::requiredRoles')
            ->first();
        if ($row === null) {
            return true;
        }
        $requiredRoles = json_decode($row->setting_value, true) ?? [];

        // Regola 4: logica OR sui ruoli dell'utente IN QUESTA rivista
        $userRoleIds = $this->getUserRoleIdsInContext($userId, $contextId);
        return !empty(array_intersect($requiredRoles, $userRoleIds));
    }

    /** True se l'utente ha il ruolo indicato in una qualsiasi rivista. */
    private function userHasRole(int $userId, int $roleId): bool
    {
        return DB::table('user_groups as ug')
            ->join('user_user_groups as uug', 'ug.user_group_id', '=', 'uug.user_group_id')
            ->where('uug.user_id', $userId)
            ->where('ug.role_id', $roleId)
            ->exists();
    }

    /** Ruoli dell'utente nella rivista indicata. */
    private function getUserRoleIdsInContext(int $userId, int $contextId): array
    {
        return DB::table('user_groups as ug')
            ->join('user_user_groups as uug', 'ug.user_group_id', '=', 'uug.user_group_id')
            ->where('uug.user_id', $userId)
            ->where('ug.context_id', $contextId)
            ->pluck('ug.role_id')
            ->unique()
            ->values()
            ->toArray();
    }
}
