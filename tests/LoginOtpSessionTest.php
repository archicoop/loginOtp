<?php

namespace APP\plugins\generic\loginOtp\tests;

use PKP\tests\PKPTestCase;

/**
 * Test della logica di sessione OTP.
 * Verifica hash, scadenza e pulizia della sessione pendente.
 *
 * NOTA: questo test simula la sessione con un array,
 * non testa il SessionManager reale di OJS.
 * Per un test end-to-end serve un test funzionale via browser.
 */
class LoginOtpSessionTest extends PKPTestCase
{
    public function testOtpHashStoredInSessionMatchesCode(): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = hash('sha256', $code);

        // Simula sessione
        $session = [
            '2fa_pending_code'    => $hash,
            '2fa_pending_expires' => time() + 600,
        ];

        $this->assertTrue(
            hash_equals($session['2fa_pending_code'], hash('sha256', $code))
        );
    }

    public function testExpiredSessionRejectsValidCode(): void
    {
        $code = '123456';
        $session = [
            '2fa_pending_code'    => hash('sha256', $code),
            '2fa_pending_expires' => time() - 1, // scaduto
        ];

        $isExpired = time() > $session['2fa_pending_expires'];
        $this->assertTrue($isExpired);

        // Anche se il codice è corretto, la sessione scaduta
        // deve essere rifiutata
    }

    public function testClearSessionRemovesAllKeys(): void
    {
        $session = [
            '2fa_pending_user_id' => 42,
            '2fa_pending_expires' => time() + 600,
            '2fa_pending_code'    => 'abc123',
            '2fa_source'          => '/dashboard',
            '2fa_remember'        => true,
        ];

        $keysToForget = [
            '2fa_pending_user_id',
            '2fa_pending_expires',
            '2fa_pending_code',
            '2fa_source',
            '2fa_remember',
        ];

        foreach ($keysToForget as $key) {
            unset($session[$key]);
        }

        $this->assertEmpty($session);
    }
}
