<?php

namespace APP\plugins\generic\loginOtp;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\loginOtp\classes\LoginOtpDAO;
use APP\plugins\generic\loginOtp\mail\LoginOtpCodeMail;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use PKP\core\PKPRequest;
use PKP\security\Validation;

class LoginOtpHandler extends Handler
{
    private LoginOtpPlugin $plugin;

    public function __construct(LoginOtpPlugin $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct();
    }

    public function authorize($request, &$args, $roleAssignments): bool
    {
        return parent::authorize($request, $args, $roleAssignments);
    }

    // ─── OTP verification ─────────────────────────────────────────────────────

    /**
     * GET  → show code entry form
     * POST → validate code, complete login on success
     */
    public function verify(array $args, PKPRequest $request): void
    {
        $session = $request->getSession();
        [$userId] = $this->validatePendingSession($session);
        if (!$userId) {
            $request->redirect(null, 'login');
            return;
        }

        $dao       = new LoginOtpDAO();
        $rateData  = $dao->getForUser($userId);
        $isLocked  = $this->isLockedOut($rateData);

        $templateMgr = TemplateManager::getManager($request);
        $this->setupTemplate($request);

        if ($request->isPost()) {
            if (!$request->checkCSRF()) {
                $request->redirect(null, 'login');
                return;
            }

            if ($isLocked) {
                $templateMgr->assign([
                    'error'     => __('plugins.generic.loginOtp.verify.error.locked'),
                    'verifyUrl' => $request->url(null, 'loginOtp', 'verify'),
                ]);
                $templateMgr->display($this->plugin->getTemplateResource('verify.tpl'));
                return;
            }

            $inputCode = trim((string) $request->getUserVar('code'));
            $storedHash = $session->get('2fa_pending_code');
            $expires    = (int) $session->get('2fa_pending_expires');

            // Check expiry
            if (!$storedHash || time() > $expires) {
                $this->clearPendingSession($session);
                $request->redirect(null, 'login');
                return;
            }

            if (hash_equals($storedHash, hash('sha256', $inputCode))) {
                $dao->resetFailedAttempts($userId);
                $this->completeLogin($request, $session, $userId);
                return;
            }

            // Wrong code
            $dao->recordFailedAttempt($userId, $rateData['failed_attempts']);
            $rateData = $dao->getForUser($userId);
            $isLocked = $this->isLockedOut($rateData);

            $templateMgr->assign([
                'error' => $isLocked
                    ? __('plugins.generic.loginOtp.verify.error.locked')
                    : __('plugins.generic.loginOtp.verify.error.invalidCode'),
            ]);
        }

        $templateMgr->assign([
            'isLocked'  => $isLocked,
            'verifyUrl' => $request->url(null, 'loginOtp', 'verify'),
        ]);
        $templateMgr->display($this->plugin->getTemplateResource('verify.tpl'));
    }

    // ─── Email sending ────────────────────────────────────────────────────────

    public static function sendOtpEmail($user, string $code, PKPRequest $request): void
    {
        $site    = $request->getSite();
        $context = $request->getContext();

        $fromEmail = $context
            ? ($context->getLocalizedData('contactEmail') ?: $site->getLocalizedContactEmail())
            : $site->getLocalizedContactEmail();
        $fromName  = $context
            ? ($context->getLocalizedData('contactName') ?: $site->getLocalizedContactName())
            : $site->getLocalizedContactName();

        $mailable = (new LoginOtpCodeMail($code, $user->getFullName()))
            ->to($user->getEmail(), $user->getFullName())
            ->from($fromEmail, $fromName);

        Mail::send($mailable);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function validatePendingSession($session): array
    {
        $userId  = $session->get('2fa_pending_user_id');
        $expires = $session->get('2fa_pending_expires');

        if (!$userId) {
            return [null];
        }
        if ($expires && time() > (int) $expires) {
            $this->clearPendingSession($session);
            return [null];
        }
        return [(int) $userId];
    }

    private function clearPendingSession($session): void
    {
        $session->forget([
            '2fa_pending_user_id',
            '2fa_pending_expires',
            '2fa_pending_code',
            '2fa_source',
            '2fa_remember',
        ]);
    }

    private function isLockedOut(array $rateData): bool
    {
        if (empty($rateData['lockout_until'])) {
            return false;
        }
        return strtotime($rateData['lockout_until']) > time();
    }

    private function completeLogin(PKPRequest $request, $session, int $userId): void
    {
        $user = Repo::user()->get($userId, false);
        if (!$user) {
            $this->clearPendingSession($session);
            $request->redirect(null, 'login');
            return;
        }

        $remember = (bool) $session->get('2fa_remember', false);
        $source   = str_replace('@', '', (string) $session->get('2fa_source', ''));

        Auth::login($user, $remember);
        $reason = null;
        Validation::registerUserSession($user, $reason);

        $this->clearPendingSession($session);

        if (preg_match('#^/\w#', $source)) {
            $request->redirectUrl($source);
        } else {
            $context = $request->getContext();
            $request->redirect($context ? $context->getPath() : null, 'dashboard');
        }
    }
}
