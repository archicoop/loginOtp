<?php

namespace APP\plugins\generic\loginOtp\mail;

use Illuminate\Mail\Mailable;

/**
 * OTP email sent during 2FA verification.
 * Extends Illuminate's Mailable directly (not PKP's Mailable, which requires DB templates).
 * Compatible with OMP and OJS — PHPMailerTransport reads getHtmlBody(), so HTML body is required.
 */
class LoginOtpCodeMail extends Mailable
{
    public function __construct(
        private string $code,
        private string $userName,
        private int    $minutes = 10
    ) {}

    public function build(): static
    {
        return $this
            ->subject(__('plugins.generic.loginOtp.email.subject'))
            ->html($this->buildHtmlBody());
    }

    private function buildHtmlBody(): string
    {
        $code    = htmlspecialchars($this->code, ENT_QUOTES, 'UTF-8');
        $name    = htmlspecialchars($this->userName, ENT_QUOTES, 'UTF-8');
        $minutes = (int) $this->minutes;

        $bodyBefore = nl2br(htmlspecialchars(
            __('plugins.generic.loginOtp.email.bodyBefore', [
                'name' => $this->userName,
            ]),
            ENT_QUOTES,
            'UTF-8'
        ));

        $bodyAfter = nl2br(htmlspecialchars(
            __('plugins.generic.loginOtp.email.bodyAfter', [
                'minutes' => $this->minutes,
            ]),
            ENT_QUOTES,
            'UTF-8'
        ));

        $footer = htmlspecialchars(
            __('plugins.generic.loginOtp.email.footer'),
            ENT_QUOTES,
            'UTF-8'
        );

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <body style="font-family:Arial,sans-serif; font-size:15px; color:#222; max-width:500px; margin:0 auto; padding:20px;">
            <p>{$bodyBefore}</p>
            <p style="font-size:28px; font-weight:bold; letter-spacing:6px; text-align:center; padding:16px; background:#f5f5f5; border-radius:6px;">{$code}</p>
            <p>{$bodyAfter}</p>
            <p style="font-size:13px; color:#666; margin-top:30px; border-top:1px solid #eee; padding-top:10px;">
                {$footer}
            </p>
        </body>
        </html>
        HTML;
    }
}
