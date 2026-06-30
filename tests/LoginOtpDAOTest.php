<?php

namespace APP\plugins\generic\loginOtp\tests;
use PKP\tests\PKPTestCase;
use APP\plugins\generic\loginOtp\classes\LoginOtpDAO;
use Illuminate\Support\Facades\DB;

/**
 * Test di integrazione per LoginOtpDAO.
 * Richiede il bootstrap completo di OJS/OMP (database, service container).
 */
class LoginOtpDAOTest extends PKPTestCase
{
    private LoginOtpDAO $dao;
    private int $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dao = new LoginOtpDAO();

        // Usa un userId che esiste nel database di test.
        // In alternativa, creane uno nel setUp e rimuovilo nel tearDown.
        $this->testUserId = $this->getTestUserId();

        // Pulisci eventuali dati residui da test precedenti
        $this->dao->resetFailedAttempts($this->testUserId);
    }

    protected function tearDown(): void
    {
        // Pulisci i dati di test
        DB::table('user_settings')
            ->where('user_id', $this->testUserId)
            ->where('setting_name', 'like', 'loginOtp_%')
            ->delete();

        parent::tearDown();
    }

    /**
     * Recupera un userId valido dal database.
     * Adatta questo metodo al tuo ambiente.
     */
    private function getTestUserId(): int
    {
        $userId = DB::table('users')->value('user_id');
        $this->assertNotNull($userId, 'Serve almeno un utente nel database di test');
        return (int) $userId;
    }

    // ── Test effettivi ──────────────────────────────

    public function testGetForUserReturnsDefaults(): void
    {
        $data = $this->dao->getForUser($this->testUserId);

        $this->assertIsArray($data);
        $this->assertEquals(0, $data['failed_attempts']);
        $this->assertEmpty($data['lockout_until']);
        $this->assertEquals(0, $data['last_otp_sent']);
    }

    public function testRecordOtpSentUpdatesTimestamp(): void
    {
        $before = time();
        $this->dao->recordOtpSent($this->testUserId);
        $after = time();

        $data = $this->dao->getForUser($this->testUserId);
        $this->assertGreaterThanOrEqual($before, $data['last_otp_sent']);
        $this->assertLessThanOrEqual($after, $data['last_otp_sent']);
    }

    public function testThrottleBlocksSecondOtpWithin60Seconds(): void
    {
        $this->dao->recordOtpSent($this->testUserId);
        $this->assertTrue($this->dao->isOtpThrottled($this->testUserId));
    }

    public function testFailedAttemptsAreIncremented(): void
    {
        $this->dao->recordFailedAttempt($this->testUserId, 0);
        $data = $this->dao->getForUser($this->testUserId);
        $this->assertEquals(1, $data['failed_attempts']);

        $this->dao->recordFailedAttempt($this->testUserId, 1);
        $data = $this->dao->getForUser($this->testUserId);
        $this->assertEquals(2, $data['failed_attempts']);
    }

    public function testLockoutTriggersAfterFiveAttempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->dao->recordFailedAttempt($this->testUserId, $i);
        }

        $data = $this->dao->getForUser($this->testUserId);
        $this->assertNotEmpty($data['lockout_until']);
        $this->assertGreaterThan(time(), strtotime($data['lockout_until']));
    }

    public function testResetClearsAttemptsAndLockout(): void
    {
        // Porta a lockout
        for ($i = 0; $i < 5; $i++) {
            $this->dao->recordFailedAttempt($this->testUserId, $i);
        }

        // Reset
        $this->dao->resetFailedAttempts($this->testUserId);

        $data = $this->dao->getForUser($this->testUserId);
        $this->assertEquals(0, $data['failed_attempts']);
        $this->assertEmpty($data['lockout_until']);
    }

    public function testSaveSettingPersistsToDatabase(): void
    {
        $this->dao->recordOtpSent($this->testUserId);

        // Verifica direttamente nel DB
        $value = DB::table('user_settings')
            ->where('user_id', $this->testUserId)
            ->where('setting_name', 'loginOtp_last_otp_sent')
            ->value('setting_value');

        $this->assertNotNull($value);
        $this->assertGreaterThan(0, (int) $value);
    }
}
