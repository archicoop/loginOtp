<?php

namespace APP\plugins\generic\loginOtp;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\loginOtp\classes\LoginOtpDAO;
use APP\plugins\generic\loginOtp\classes\LoginOtpSettingsForm;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Validation;
use PKP\security\Role;
use Illuminate\Support\Facades\DB;

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
        return false;
    }

    public function getCanDisable(): bool
    {
        return false;
    }

    public function register($category, $path, $mainContextId = null): bool
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        Hook::add('LoadHandler', $this->handleLoadHandler(...));
        Hook::add('Authentication::authenticate', $this->handleAuthenticate(...));

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
                /*$contextId = $request->getContext()?->getId() ?? 0;*/
                $context = $request->getContext();
                if (!$context) {
                    return new JSONMessage(false, __('plugins.generic.loginOtp.settings.error.noContext'));
                }
                $contextId = (int)$context->getId();
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

    /**
     * Gestisce l'autenticazione per login di SITO
     */
    public function handleAuthenticate($hookName, $args): bool
    {
        $authenticated = &$args[3];
        $user = &$args[4];
        $reason = &$args[2];

        // Se autenticazione già fallita, esci
        if (!$authenticated || !$user) {
            return Hook::CONTINUE;
        }

        $request = Application::get()->getRequest();
        $context = $request->getContext();

        // Se c'è un contesto (rivista), lascia gestire a LoadHandler
        if ($context !== null && $context->getId() > 0) {
            return Hook::CONTINUE;
        }

        // LOGIN DI SITO - verifica se richiede OTP
        if ($this->userRequires2FAForAnyContext($user->getId())) {
            $this->storePendingUserInSession($user, $request);
            $authenticated = false;
            $reason = 'otp_required';
            return Hook::ABORT;
        }

        return Hook::CONTINUE;
    }

    /**
     * Determina se l'utente richiede 2FA in ALMENO UNA rivista (logica OR)
     *
     * @param int $userId
     * @return bool
     */
    private function userRequires2FAForAnyContext(int $userId): bool
    {
        // 1. Site Admin richiede sempre 2FA
        if ($this->userHasRole($userId, Role::ROLE_ID_SITE_ADMIN)) {
            return true;
        }

        // 2. Ottieni TUTTI i ruoli dell'utente in TUTTE le riviste
        $userRolesByContext = $this->getAllUserRolesByContext($userId);

        if (empty($userRolesByContext)) {
            return false;
        }

        // 3. Per OGNI rivista in cui l'utente ha ruoli, controlla se richiede OTP
        foreach ($userRolesByContext as $contextId => $roleIds) {
            // Recupera le impostazioni OTP per questa rivista
            $requiredRoles = $this->getSetting($contextId, 'requiredRoles');

            // Se la rivista non ha configurazione OTP, salta (default: no OTP)
            if ($requiredRoles === null) {
                continue;
            }

            // Se l'utente ha ALMENO UNO dei ruoli richiesti in questa rivista
            $hasRequiredRole = !empty(array_intersect($requiredRoles, $roleIds));

            if ($hasRequiredRole) {
                return true;
            }

        }

        return false;
    }

    /**
     * Ottiene TUTTI i ruoli dell'utente organizzati per contesto (rivista)
     *
     * @param int $userId
     * @return array [contextId => [roleIds]]
     */
    private function getAllUserRolesByContext(int $userId): array
    {
        $results = DB::table('user_groups as ug')
            ->join('user_user_groups as uug', 'ug.user_group_id', '=', 'uug.user_group_id')
            ->where('uug.user_id', $userId)
            ->whereNotNull('ug.context_id')
            ->where('ug.context_id', '>', 0)  // Solo contesti validi (riviste)
            ->select('ug.context_id', 'ug.role_id')
            ->get();

        $rolesByContext = [];
        foreach ($results as $row) {
            $contextId = (int)$row->context_id;
            $roleId = (int)$row->role_id;

            if (!isset($rolesByContext[$contextId])) {
                $rolesByContext[$contextId] = [];
            }

            if (!in_array($roleId, $rolesByContext[$contextId])) {
                $rolesByContext[$contextId][] = $roleId;
            }
        }

        return $rolesByContext;
    }

    /**
     * Salva utente pendente in sessione per il flusso OTP
     */
    private function storePendingUserInSession($user, $request): void
    {
        $session = $request->getSession();
        $session->put('2fa_pending_user_id', $user->getId());
        $session->put('2fa_pending_expires', time() + 600);
        $session->put('2fa_source', 'site_login');
    }

    private function interceptSignIn($request): bool
    {

        $context = $request->getContext();
        $contextId = $context ? (int)$context->getId() : 0;

        if ($contextId > 0 && !$this->getEnabled($contextId)) {
            return Hook::CONTINUE;
        }

        if (!$request->checkCSRF()) {
            return Hook::CONTINUE;
        }

        $username = trim((string)$request->getUserVar('username'));
        if ($username === '') {
            return Hook::CONTINUE;
        }

        $user = Repo::user()->getByUsername($username, true)
            ?? Repo::user()->getByEmail($username, true);

        if (!$user || $user->getDisabled()) {
            return Hook::CONTINUE;
        }

        $password = (string)$request->getUserVar('password');
        $rehash = null;
        if (!Validation::verifyPassword($user->getUsername(), $password, $user->getPassword(), $rehash)) {
            return Hook::CONTINUE;
        }

        if (!empty($rehash)) {
            $user->setPassword($rehash);
            Repo::user()->edit($user);
        }

        $requires = $this->userRequires2FA($user->getId(), $request);
        if (!$requires) {
            return Hook::CONTINUE;
        }

        $dao = new LoginOtpDAO();
        $session = $request->getSession();

        $pendingCode = $session->get('2fa_pending_code');
        $pendingExpires = (int)$session->get('2fa_pending_expires');
        $hasValidPendingCode = $pendingCode && time() < $pendingExpires;

        if ($dao->isOtpThrottled($user->getId()) && $hasValidPendingCode) {
            $request->redirect(null, 'loginOtp', 'verify');
            return Hook::ABORT;
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = hash('sha256', $code);

        $session->put('2fa_pending_user_id', $user->getId());
        $session->put('2fa_pending_expires', time() + 600);
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

        // Regola 2-bis: Journal Manager in QUALUNQUE rivista → sempre 2FA
        // (un JM ha privilegi che lo seguono in tutto il sito, non solo
        // nella rivista che gestisce; coerente con la Regola 2 sul Site Admin)
        if ($this->userHasRole($userId, Role::ROLE_ID_MANAGER)) {
            return true;
        }

        $userRolesInContext = $this->getUserRoleIdsInContext($userId, $contextId);

        // Regola 2-ter: utente senza ruoli sulla rivista corrente → sempre 2FA
        // (principio di cautela: login su rivista dove non si hanno ruoli
        // è equivalente a un login "esterno" alla rivista stessa)
        if (empty($userRolesInContext)) {
            return true;
        }

        // Regola 3: non configurato per questa rivista → default sicuro
        $requiredRoles = $this->getSetting($contextId, 'requiredRoles');
        if ($requiredRoles === null) {
            return true;
        }

        // Regola 4: logica OR
        $hasMatch = !empty(array_intersect($requiredRoles, $userRolesInContext));
        return $hasMatch;
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
