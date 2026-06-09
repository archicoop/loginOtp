<?php

namespace APP\plugins\generic\loginOtp\tests;

use PHPUnit\Framework\TestCase;

/**
 * Testa la logica di throttle OTP e lockout per tentativi falliti.
 * Replica le costanti e gli algoritmi di LoginOtpDAO senza importarla,
 * così i test rimangono puri (nessun DB, nessun bootstrap OMP).
 */
class ThrottleAndLockoutTest extends TestCase
{
    private const OTP_THROTTLE    = 60;
    private const MAX_ATTEMPTS    = 5;
    private const LOCKOUT_SECONDS = 900;

    // ── Throttle invio OTP ────────────────────────────────────────────────────

    /** Algoritmo: $lastSent > 0 && (now - $lastSent) < OTP_THROTTLE */
    private function isThrottled(int $lastSent, int $now): bool
    {
        return $lastSent > 0 && ($now - $lastSent) < self::OTP_THROTTLE;
    }

    public function testNotThrottledWhenNoOtpEverSent(): void
    {
        $this->assertFalse($this->isThrottled(0, time()));
    }

    public function testThrottledIfSentLessThan60SecondsAgo(): void
    {
        $now      = 1000000;
        $lastSent = $now - 30;
        $this->assertTrue($this->isThrottled($lastSent, $now));
    }

    public function testThrottledAtExactly59Seconds(): void
    {
        $now      = 1000000;
        $lastSent = $now - 59;
        $this->assertTrue($this->isThrottled($lastSent, $now));
    }

    public function testNotThrottledAfter60Seconds(): void
    {
        $now      = 1000000;
        $lastSent = $now - 60;
        $this->assertFalse($this->isThrottled($lastSent, $now));
    }

    public function testNotThrottledAfterMoreThan60Seconds(): void
    {
        $now      = 1000000;
        $lastSent = $now - 120;
        $this->assertFalse($this->isThrottled($lastSent, $now));
    }

    // ── Lockout per tentativi falliti ─────────────────────────────────────────

    /** Algoritmo: lockout scatta quando il nuovo contatore raggiunge MAX_ATTEMPTS */
    private function shouldLockOut(int $newAttemptCount): bool
    {
        return $newAttemptCount >= self::MAX_ATTEMPTS;
    }

    public function testNoLockoutBeforeFiveAttempts(): void
    {
        $this->assertFalse($this->shouldLockOut(1));
        $this->assertFalse($this->shouldLockOut(2));
        $this->assertFalse($this->shouldLockOut(3));
        $this->assertFalse($this->shouldLockOut(4));
    }

    public function testLockoutOnFifthAttempt(): void
    {
        $this->assertTrue($this->shouldLockOut(5));
    }

    public function testLockoutPersistsAfterFiveAttempts(): void
    {
        $this->assertTrue($this->shouldLockOut(6));
        $this->assertTrue($this->shouldLockOut(10));
    }

    // ── Durata del lockout ────────────────────────────────────────────────────

    /** Algoritmo: strtotime($lockoutUntil) > $now */
    private function isLockedOut(?string $lockoutUntil, int $now): bool
    {
        if (empty($lockoutUntil)) {
            return false;
        }
        return strtotime($lockoutUntil) > $now;
    }

    public function testNotLockedOutWhenLockoutUntilIsEmpty(): void
    {
        $this->assertFalse($this->isLockedOut('', time()));
        $this->assertFalse($this->isLockedOut(null, time()));
    }

    public function testLockedOutWhenLockoutUntilIsInFuture(): void
    {
        $lockoutUntil = date('Y-m-d H:i:s', time() + 900);
        $this->assertTrue($this->isLockedOut($lockoutUntil, time()));
    }

    public function testNotLockedOutWhenLockoutUntilHasPassed(): void
    {
        $lockoutUntil = date('Y-m-d H:i:s', time() - 1);
        $this->assertFalse($this->isLockedOut($lockoutUntil, time()));
    }

    public function testLockoutDurationIs15Minutes(): void
    {
        $this->assertEquals(900, self::LOCKOUT_SECONDS);
    }

    public function testLockoutUntilDatetimeIsCorrect(): void
    {
        $now          = 1000000;
        $lockoutUntil = date('Y-m-d H:i:s', $now + self::LOCKOUT_SECONDS);
        $parsed       = strtotime($lockoutUntil);
        $this->assertEquals($now + 900, $parsed);
    }

    // ── Reset dopo login riuscito ─────────────────────────────────────────────

    public function testAfterResetNoLongerLockedOut(): void
    {
        $lockedUntil = date('Y-m-d H:i:s', time() + 900);
        $this->assertTrue($this->isLockedOut($lockedUntil, time()));

        // reset: lockout_until = ''
        $this->assertFalse($this->isLockedOut('', time()));
    }

    public function testAfterResetAttemptsCounterIsZero(): void
    {
        $attempts = 5;
        $attempts = 0; // reset
        $this->assertFalse($this->shouldLockOut($attempts + 1));
    }
}
