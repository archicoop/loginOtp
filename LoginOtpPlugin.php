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
use PKP\config\Config;
use PKP\form\validation\FormValidatorAltcha;
use PKP\form\validation\FormValidatorReCaptcha;

class LoginOtpPlugin extends GenericPlugin
{

    /**
     * Ruoli per cui l'OTP è sempre richiesto, in qualunque rivista e da
     * qualunque rivista si faccia login. Non configurabili per rivista.
     */
    public const ALWAYS_REQUIRED_ROLES = [
        Role::ROLE_ID_SITE_ADMIN,
        Role::ROLE_ID_MANAGER,
        Role::ROLE_ID_SUBSCRIPTION_MANAGER,
    ];

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

        if (Config::getVar('loginOtp', 'disabled', false)) {
            return true;
        }

        Hook::add('LoadHandler', $this->handleLoadHandler(...), Hook::SEQUENCE_LATE);

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

        if ($page === 'login' && $op === 'savePassword') {
            $request = Application::get()->getRequest();
            if (!$request->isPost()) {
                return Hook::CONTINUE;
            }
            return $this->guardSavePassword($request);
        }

        return Hook::CONTINUE;
    }

    private function interceptSignIn($request): bool
    {
        // Controlli preliminari di LoginHandler::signIn(): se si applicano, decide il core
        if (Validation::isLoggedIn()) {
            return Hook::CONTINUE;
        }
        if (Config::getVar('security', 'force_login_ssl') && $request->getProtocol() != 'https') {
            return Hook::CONTINUE;
        }

        $context = $request->getContext();
        $contextId = $context ? (int)$context->getId() : 0;

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

        if (!$this->captchaPassed($request)) {
            return Hook::CONTINUE;
        }

        $dao = new LoginOtpDAO();
        $session = $request->getSession();

        $session->put('2fa_context_path', $context ? $context->getPath() : Application::SITE_CONTEXT_PATH);

        $pendingCode = $session->get('2fa_pending_code');
        $pendingExpires = (int)$session->get('2fa_pending_expires');
        $pendingUserId = (int)$session->get('2fa_pending_user_id');
        $hasValidPendingCode = $pendingCode && time() < $pendingExpires
                                && time() < $pendingExpires
                                && $pendingUserId === $user->getId();

        if (!$dao->canSendOtp($user->getId())) {
            if ($hasValidPendingCode) {
                // Stessa sessione: il codice già inviato è ancora valido
                $request->redirect(Application::SITE_CONTEXT_PATH, 'loginOtp', 'verify');
                return Hook::ABORT;
            }
            $request->redirect(null, 'login', null, null, [
                'loginMessage' => 'plugins.generic.loginOtp.error.throttled',
            ]);
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

        $request->redirect(Application::SITE_CONTEXT_PATH, 'loginOtp', 'verify');
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

        // Regola 2: Site Admin, Manager (Journal manager, Journal editor,
        // Production editor) o Subscription Manager in QUALUNQUE rivista
        // → sempre 2FA. Sono privilegi che la sessione, valida su tutto il
        // sito, porta con sé ovunque.
        if ($this->userHasAnyRole($userId, self::ALWAYS_REQUIRED_ROLES)) {
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

    private function userHasAnyRole(int $userId, array $roleIds): bool
    {
        return DB::table('user_groups as ug')
            ->join('user_user_groups as uug', 'ug.user_group_id', '=', 'uug.user_group_id')
            ->where('uug.user_id', $userId)
            ->whereIn('ug.role_id', $roleIds)
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

    /**
     * login/savePassword autentica direttamente (Validation::login nel core).
     * Per gli utenti soggetti a OTP è ammesso solo dopo un OTP verificato,
     * cioè con il permesso lasciato da LoginOtpHandler::completeLogin().
     */
    private function guardSavePassword($request): bool
    {
        $username = trim((string)$request->getUserVar('username'));
        if ($username === '') {
            return Hook::CONTINUE;
        }

        $user = Repo::user()->getByUsername($username, true)
            ?? Repo::user()->getByEmail($username, true);
        if (!$user || $user->getDisabled()) {
            return Hook::CONTINUE;
        }

        if (!$this->userRequires2FA($user->getId(), $request)) {
            return Hook::CONTINUE;
        }

        $session = $request->getSession();
        $allowedUserId = (int)$session->get('loginOtp_pwchange_user_id');
        $allowedUntil = (int)$session->get('loginOtp_pwchange_expires');
        if ($allowedUserId === $user->getId() && time() < $allowedUntil) {
            return Hook::CONTINUE;
        }

        $request->redirect(null, 'login');
        return Hook::ABORT;
    }

    /**
     * Replica i controlli captcha di LoginHandler::signIn().
     * Va chiamato solo dopo aver verificato la password: il token reCAPTCHA
     * è monouso, e consumarlo anche per le password errate farebbe trovare
     * al core un token già usato (errore captcha al posto dell'errore di
     * login, che betterPassword conta).
     */
    private function captchaPassed($request): bool
    {
        if (Config::getVar('captcha', 'captcha_on_login') && Config::getVar('captcha', 'recaptcha')) {
            try {
                FormValidatorReCaptcha::validateResponse(
                    $request->getUserVar('g-recaptcha-response'),
                    $request->getRemoteAddr(),
                    $request->getServerHost()
                );
            } catch (\Exception $e) {
                return false;
            }
        }

        if (Config::getVar('captcha', 'altcha_on_login') && Config::getVar('captcha', 'altcha')) {
            try {
                FormValidatorAltcha::validateResponse(
                    $request->getUserVar('altcha'),
                    $request->getRemoteAddr()
                );
            } catch (\Exception $e) {
                return false;
            }
        }

        return true;
    }
}
