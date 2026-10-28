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
    private const MAX_OTP_PER_HOUR = 3;
    private const OTP_WINDOW       = 3600;

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
            'otp_window_start' => (int) ($rows[self::PREFIX . 'otp_window_start'] ?? 0),
            'otp_window_count' => (int) ($rows[self::PREFIX . 'otp_window_count'] ?? 0),
        ];
    }

    public function recordOtpSent(int $userId): void
    {
        $s = $this->getForUser($userId);
        $now = time();

        if ($s['otp_window_start'] === 0 || ($now - $s['otp_window_start']) >= self::OTP_WINDOW) {
            $this->saveSetting($userId, 'otp_window_start', (string) $now);
            $this->saveSetting($userId, 'otp_window_count', '1');
        } else {
            $this->saveSetting($userId, 'otp_window_count', (string) ($s['otp_window_count'] + 1));
        }

        $this->saveSetting($userId, 'last_otp_sent', (string) $now);
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

    /**
     * Throttle per utente, indipendente dalla sessione: al massimo un invio
     * ogni OTP_THROTTLE secondi e MAX_OTP_PER_HOUR invii per finestra oraria.
     */
    public function canSendOtp(int $userId): bool
    {
        $s = $this->getForUser($userId);
        $now = time();

        if ($s['last_otp_sent'] > 0 && ($now - $s['last_otp_sent']) < self::OTP_THROTTLE) {
            return false;
        }

        if ($s['otp_window_start'] > 0
            && ($now - $s['otp_window_start']) < self::OTP_WINDOW
            && $s['otp_window_count'] >= self::MAX_OTP_PER_HOUR) {
            return false;
        }

        return true;
    }
}
