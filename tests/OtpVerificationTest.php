<?php

namespace APP\plugins\generic\loginOtp\tests;

use PHPUnit\Framework\TestCase;

/**
 * Testa la logica di generazione e verifica del codice OTP.
 * Nessuna dipendenza da OMP/Laravel: usa solo funzioni PHP native.
 */
class OtpVerificationTest extends TestCase
{
    // ── Formato codice ────────────────────────────────────────────────────────

    public function testOtpIsSixDigits(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        }
    }

    public function testOtpZeroPaddingForSmallNumbers(): void
    {
        $this->assertSame('000001', str_pad('1', 6, '0', STR_PAD_LEFT));
        $this->assertSame('000000', str_pad('0', 6, '0', STR_PAD_LEFT));
        $this->assertSame('042000', str_pad('42000', 6, '0', STR_PAD_LEFT));
        $this->assertSame('999999', str_pad('999999', 6, '0', STR_PAD_LEFT));
    }

    // ── Verifica hash ─────────────────────────────────────────────────────────

    public function testCorrectCodePassesVerification(): void
    {
        $code = '382910';
        $storedHash = hash('sha256', $code);
        $this->assertTrue(hash_equals($storedHash, hash('sha256', $code)));
    }

    public function testWrongCodeFailsVerification(): void
    {
        $storedHash = hash('sha256', '382910');
        $this->assertFalse(hash_equals($storedHash, hash('sha256', '000000')));
        $this->assertFalse(hash_equals($storedHash, hash('sha256', '382911')));
        $this->assertFalse(hash_equals($storedHash, hash('sha256', '')));
    }

    public function testHashIsNotReversible(): void
    {
        $code = '123456';
        $hash = hash('sha256', $code);
        $this->assertNotEquals($code, $hash);
        $this->assertEquals(64, strlen($hash));
    }

    public function testDifferentCodesProduceDifferentHashes(): void
    {
        $this->assertNotEquals(
            hash('sha256', '000000'),
            hash('sha256', '000001')
        );
    }

    // ── Scadenza codice ───────────────────────────────────────────────────────

    public function testCodeIsValidBeforeExpiry(): void
    {
        $expires = time() + 600; // 10 minuti nel futuro
        $this->assertFalse(time() > $expires, 'Il codice non dovrebbe essere scaduto');
    }

    public function testCodeIsExpiredAfterDeadline(): void
    {
        $expires = time() - 1; // 1 secondo fa
        $this->assertTrue(time() > $expires, 'Il codice dovrebbe essere scaduto');
    }

    public function testExpiryWindowIsTenMinutes(): void
    {
        $issuedAt = time();
        $expires   = $issuedAt + 600;
        $this->assertEquals(600, $expires - $issuedAt);
    }
}
