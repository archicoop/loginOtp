<?php

namespace APP\plugins\generic\loginOtp\classes;

use Illuminate\Support\Facades\DB;

/**
 * Persists per-user rate-limiting data (failed attempts and lockout) in user_settings.
 * Compatible with OMP and OJS — the OTP code itself lives only in the session.
 */
class LoginOtpDAO
{
    private const PREFIX          = 'loginOtp_';
    private const MAX_ATTEMPTS    = 5;
    private const LOCKOUT_SECONDS = 900;  // 15 minutes
    private const OTP_THROTTLE    = 60;   // seconds between OTP sends

    public function getForUser(int $userId): array
    {
        $rows = DB::table('user_settings')
            ->where('user_id', $userId)
            ->where('setting_name', 'like', self::PREFIX . '%')
            ->pluck('setting_value', 'setting_name')
            ->toArray();

        return [
            'failed_attempts' => (int) ($rows[self::PREFIX . 'failed_attempts'] ?? 0),
            'lockout_until'   => $rows[self::PREFIX . 'lockout_until'] ?? null,
            'last_otp_sent'   => (int) ($rows[self::PREFIX . 'last_otp_sent'] ?? 0),
        ];
    }

    public function isOtpThrottled(int $userId): bool
    {
        $lastSent = (int) DB::table('user_settings')
            ->where('user_id', $userId)
            ->where('locale', '')
            ->where('setting_name', self::PREFIX . 'last_otp_sent')
            ->value('setting_value');

        return $lastSent > 0 && (time() - $lastSent) < self::OTP_THROTTLE;
    }

    public function recordOtpSent(int $userId): void
    {
        $this->saveSetting($userId, 'last_otp_sent', (string) time());
    }

    public function recordFailedAttempt(int $userId, int $currentAttempts): void
    {
        $newCount = $currentAttempts + 1;
        $this->saveSetting($userId, 'failed_attempts', (string) $newCount);

        if ($newCount >= self::MAX_ATTEMPTS) {
            $this->saveSetting($userId, 'lockout_until',
                date('Y-m-d H:i:s', time() + self::LOCKOUT_SECONDS)
            );
        }
    }

    public function resetFailedAttempts(int $userId): void
    {
        $this->saveSetting($userId, 'failed_attempts', '0');
        $this->saveSetting($userId, 'lockout_until',   '');
    }

    private function saveSetting(int $userId, string $key, string $value): void
    {
        DB::table('user_settings')->updateOrInsert(
            ['user_id' => $userId, 'setting_name' => self::PREFIX . $key, 'locale' => ''],
            ['setting_value' => $value]
        );
    }
}
