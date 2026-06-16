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

    /**
     * Attiva i log diagnostici scritti in <plugin>/debug.log.
     * Da impostare a true solo in sviluppo. NON committare con true.
     */
    private const DEBUG = true;

    private function logDebug(string $message): void
    {
        if (!self::DEBUG) {
            return;
        }
        file_put_contents(
            __DIR__ . '/debug.log',
            '[' . date('H:i:s') . '] ' . $message . "\n",
            FILE_APPEND
        );
    }

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
        $this->$this->logDebug("\n[" . date('H:i:s') . "] === userRequires2FAForAnyContext ===\n");
        $this->logDebug("Checking user ID: {$userId}\n");

        // 1. Site Admin richiede sempre 2FA
        if ($this->userHasRole($userId, Role::ROLE_ID_SITE_ADMIN)) {
            $this->logDebug("Site admin role found → 2FA REQUIRED\n");
            return true;
        }

        // 2. Ottieni TUTTI i ruoli dell'utente in TUTTE le riviste
        $userRolesByContext = $this->getAllUserRolesByContext($userId);

        if (empty($userRolesByContext)) {
            $this->logDebug("No roles found in any context → 2FA NOT REQUIRED\n");
            return false;
        }

        $this->logDebug("User has roles in contexts: " . implode(', ', array_keys($userRolesByContext)) . "\n");

        // 3. Per OGNI rivista in cui l'utente ha ruoli, controlla se richiede OTP
        foreach ($userRolesByContext as $contextId => $roleIds) {
            // Recupera le impostazioni OTP per questa rivista
            $requiredRoles = $this->getSetting($contextId, 'requiredRoles');

            $this->logDebug("  Context {$contextId}: roles=" . json_encode($roleIds) . ", requiredRoles=" . json_encode($requiredRoles) . "\n");

            // Se la rivista non ha configurazione OTP, salta (default: no OTP)
            if ($requiredRoles === null) {
                $this->logDebug("    → No OTP configuration for this context, skipping\n");
                continue;
            }

            // Se l'utente ha ALMENO UNO dei ruoli richiesti in questa rivista
            $hasRequiredRole = !empty(array_intersect($requiredRoles, $roleIds));

            if ($hasRequiredRole) {
                $this->logDebug("    → MATCH found! 2FA REQUIRED for context {$contextId}\n");
                return true;
            }

            $this->logDebug("    → No match in this context\n");
        }

        $this->logDebug("No matching roles found in any context → 2FA NOT REQUIRED\n");
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
        $this->logDebug("[" . date('H:i:s') . "] === interceptSignIn called ===\n");

        $context = $request->getContext();
        $contextId = $context ? (int)$context->getId() : 0;
        $this->logDebug("  contextId=$contextId, enabled=" . ($contextId > 0 ? ($this->getEnabled($contextId) ? "yes" : "NO") : "(check skipped)") . "\n");

        if ($contextId > 0 && !$this->getEnabled($contextId)) {
            $this->logDebug("  EXIT: plugin not enabled for this context\n\n");
            return Hook::CONTINUE;
        }

        if (!$request->checkCSRF()) {
            $this->logDebug("  EXIT: CSRF check failed\n\n");
            return Hook::CONTINUE;
        }
        $this->logDebug("  CSRF ok\n");

        $username = trim((string)$request->getUserVar('username'));
        if ($username === '') {
            $this->logDebug("  EXIT: empty username\n\n");
            return Hook::CONTINUE;
        }
        $this->logDebug("  username=$username\n");

        $user = Repo::user()->getByUsername($username, true)
            ?? Repo::user()->getByEmail($username, true);

        if (!$user || $user->getDisabled()) {
            $this->logDebug("  EXIT: user not found or disabled\n\n");
            return Hook::CONTINUE;
        }
        $this->logDebug("  user found, id=" . $user->getId() . "\n");

        $password = (string)$request->getUserVar('password');
        $rehash = null;
        if (!Validation::verifyPassword($user->getUsername(), $password, $user->getPassword(), $rehash)) {
            file_put_contents("  EXIT: password verification failed\n\n");
            return Hook::CONTINUE;
        }
        $this->logDebug("  password verified\n");

        if (!empty($rehash)) {
            $user->setPassword($rehash);
            Repo::user()->edit($user);
        }

        $this->logDebug("  about to call userRequires2FA, userId=" . $user->getId() . "\n");
        $requires = $this->userRequires2FA($user->getId(), $request);
        $this->logDebug("  userRequires2FA=" . ($requires ? "TRUE" : "FALSE") . "\n");
        if (!$requires) {
            $this->logDebug("  EXIT: 2FA not required for this user\n\n");
            return Hook::CONTINUE;
        }

        $this->logDebug("  2FA REQUIRED, proceeding with OTP\n\n");

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
     * METODO ESISTENTE - Da modificare per usare la stessa logica
     * Questo viene chiamato da interceptSignIn per i login di rivista
     */
    /*    private function userRequires2FA(int $userId, $request): bool {
            // Per i login di rivista, usa la stessa logica OR
            // Puoi chiamare direttamente userRequires2FAForAnyContext()
            return $this->userRequires2FAForAnyContext($userId);
        }*/

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
        $this->logDebug("  >>> userRequires2FA entered, userId=$userId\n");

        $context = $request->getContext();
        $this->logDebug("  context=" . ($context === null ? "NULL" : "id=" . $context->getId()) . "\n");

        // Regola 1: login di sito → sempre 2FA
        if ($context === null || (int)$context->getId() === 0) {
            $this->logDebug("  Regola 1 (no context) -> TRUE\n");
            return true;
        }
        $contextId = (int)$context->getId();
        $this->logDebug("  Regola 1 passed, contextId=$contextId\n");

        // Regola 2: Site Admin → sempre 2FA (ruolo non legato a una rivista)
        $this->logDebug("  about to check Regola 2 (Site Admin)\n");
        if ($this->userHasRole($userId, Role::ROLE_ID_SITE_ADMIN)) {
            $this->logDebug("  Regola 2 (site admin) -> TRUE\n");
            return true;
        }
        $this->logDebug("  Regola 2 passed\n");

        // Regola 2-bis: Journal Manager in QUALUNQUE rivista → sempre 2FA
        // (un JM ha privilegi che lo seguono in tutto il sito, non solo
        // nella rivista che gestisce; coerente con la Regola 2 sul Site Admin)
        $this->logDebug("  checking Manager role (ID=" . Role::ROLE_ID_MANAGER . ")\n");
        if ($this->userHasRole($userId, Role::ROLE_ID_MANAGER)) {
            $this->logDebug("  Regola 2-bis (manager anywhere) -> TRUE");
            return true;
        }
        $this->logDebug("  Regola 2-bis passed\n");


        $this->logDebug("  about to call getUserRoleIdsInContext\n");
        $userRolesInContext = $this->getUserRoleIdsInContext($userId, $contextId);
        $this->logDebug("  userRolesInContext=" . json_encode($userRolesInContext) . "\n");

        // Regola 2-ter: utente senza ruoli sulla rivista corrente → sempre 2FA
        // (principio di cautela: login su rivista dove non si hanno ruoli
        // è equivalente a un login "esterno" alla rivista stessa)
        if (empty($userRolesInContext)) {
            $this->logDebug("  Regola 2-ter (no roles in context) -> TRUE\n");
            return true;
        }

        // Regola 3: non configurato per questa rivista → default sicuro
        $requiredRoles = $this->getSetting($contextId, 'requiredRoles');
        $this->logDebug("  requiredRoles=" . json_encode($requiredRoles) . "\n");
        if ($requiredRoles === null) {
            $this->logDebug("  Regola 3 (not configured) -> TRUE\n");
            return true;
        }

        // Regola 4: logica OR
        $hasMatch = !empty(array_intersect($requiredRoles, $userRolesInContext));
        $this->logDebug("  Regola 4 (OR logic) -> " . ($hasMatch ? "TRUE" : "FALSE") . "\n");
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
