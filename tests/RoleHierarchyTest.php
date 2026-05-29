<?php

namespace APP\plugins\generic\loginOtp\tests;

use PHPUnit\Framework\TestCase;

/**
 * Testa la logica di gerarchia ruoli per la determinazione della 2FA.
 *
 * Replica l'algoritmo di userRequires2FA() senza dipendenze da OJS/DB.
 * I valori delle costanti Role::ROLE_ID_* sono i bitmask standard PKP.
 */
class RoleHierarchyTest extends TestCase
{
    // Costanti PKP standard (bitmask)
    private const ROLE_SITE_ADMIN          = 1;
    private const ROLE_MANAGER             = 16;
    private const ROLE_SUB_EDITOR          = 17;       // valore da verificare sulla tua installazione
    private const ROLE_ASSISTANT           = 4097;     // valore da verificare sulla tua installazione
    private const ROLE_REVIEWER            = 4096;
    private const ROLE_AUTHOR              = 65536;
    private const ROLE_READER              = 1048576;
    private const ROLE_SUBSCRIPTION_MGR    = 2097152;

    private const ROLE_HIERARCHY = [
        self::ROLE_SITE_ADMIN,
        self::ROLE_MANAGER,
        self::ROLE_SUB_EDITOR,
        self::ROLE_ASSISTANT,
        self::ROLE_REVIEWER,
        self::ROLE_AUTHOR,
        self::ROLE_READER,
        self::ROLE_SUBSCRIPTION_MGR,
    ];

    /**
     * Replica la logica di userRequires2FA() per il test.
     *
     * @param array $requiredRoles  Ruoli selezionati nelle impostazioni (richiedono 2FA)
     * @param array $userRoleIds    Ruoli assegnati all'utente
     * @return bool
     */
    private function userRequires2FA(?array $requiredRoles, array $userRoleIds): bool
    {
        // Not configured → require for everyone
        if ($requiredRoles === null) {
            return true;
        }

        // Empty → no one needs 2FA
        if (empty($requiredRoles)) {
            return false;
        }

        // Find highest-privilege role
        foreach (self::ROLE_HIERARCHY as $role) {
            if (in_array($role, $userRoleIds)) {
                return in_array($role, $requiredRoles);
            }
        }

        // No recognised role → require 2FA
        return true;
    }

    // ── Caso base: configurazione null (default) ──────────────────────────────

    public function testNullSettingRequires2FAForEveryone(): void
    {
        $this->assertTrue($this->userRequires2FA(null, [self::ROLE_AUTHOR]));
        $this->assertTrue($this->userRequires2FA(null, [self::ROLE_SITE_ADMIN]));
        $this->assertTrue($this->userRequires2FA(null, []));
    }

    // ── Caso base: nessun ruolo selezionato ───────────────────────────────────

    public function testEmptySettingDisables2FAForEveryone(): void
    {
        $this->assertFalse($this->userRequires2FA([], [self::ROLE_SITE_ADMIN]));
        $this->assertFalse($this->userRequires2FA([], [self::ROLE_AUTHOR]));
    }

    // ── Il tuo scenario: Site Admin esente, ha anche Manager e Author ─────────

    public function testSiteAdminExemptEvenWithLowerRoles(): void
    {
        // 2FA richiesta per Manager, Author, Reader — ma NON per Site Admin
        $requiredRoles = [self::ROLE_MANAGER, self::ROLE_AUTHOR, self::ROLE_READER];

        // Utente: Site Admin + Manager + Author + Reader
        $userRoles = [self::ROLE_SITE_ADMIN, self::ROLE_MANAGER, self::ROLE_AUTHOR, self::ROLE_READER];

        // Il ruolo più elevato (Site Admin) NON è nella lista → 2FA non richiesta
        $this->assertFalse($this->userRequires2FA($requiredRoles, $userRoles));
    }

    // ── Manager esente, utente è Manager + Author ─────────────────────────────

    public function testManagerExemptEvenWithAuthorRole(): void
    {
        $requiredRoles = [self::ROLE_AUTHOR, self::ROLE_REVIEWER];
        $userRoles = [self::ROLE_MANAGER, self::ROLE_AUTHOR];

        // Manager è il ruolo più elevato, non è nella lista → esente
        $this->assertFalse($this->userRequires2FA($requiredRoles, $userRoles));
    }

    // ── Utente con solo ruolo Author, Author è richiesto ──────────────────────

    public function testAuthorAloneRequires2FA(): void
    {
        $requiredRoles = [self::ROLE_AUTHOR, self::ROLE_REVIEWER];
        $userRoles = [self::ROLE_AUTHOR];

        $this->assertTrue($this->userRequires2FA($requiredRoles, $userRoles));
    }

    // ── Utente con solo ruolo Reader, Reader non nella lista ──────────────────

    public function testReaderExemptWhenNotInList(): void
    {
        $requiredRoles = [self::ROLE_AUTHOR, self::ROLE_MANAGER];
        $userRoles = [self::ROLE_READER];

        $this->assertFalse($this->userRequires2FA($requiredRoles, $userRoles));
    }

    // ── Tutti i ruoli richiesti, utente con qualsiasi ruolo → richiede 2FA ────

    public function testAllRolesRequiredAlwaysRequires2FA(): void
    {
        $allRoles = self::ROLE_HIERARCHY;

        $this->assertTrue($this->userRequires2FA($allRoles, [self::ROLE_SITE_ADMIN]));
        $this->assertTrue($this->userRequires2FA($allRoles, [self::ROLE_AUTHOR]));
        $this->assertTrue($this->userRequires2FA($allRoles, [self::ROLE_SITE_ADMIN, self::ROLE_AUTHOR]));
    }

    // ── Utente senza ruoli riconosciuti → 2FA per sicurezza ───────────────────

    public function testUnknownRoleDefaults2FA(): void
    {
        $requiredRoles = [self::ROLE_AUTHOR];
        $userRoles = [999999]; // ruolo non nella gerarchia

        $this->assertTrue($this->userRequires2FA($requiredRoles, $userRoles));
    }

    // ── Gerarchia: il primo match vince ───────────────────────────────────────

    public function testHierarchyOrderMatters(): void
    {
        // Solo Reviewer richiede 2FA
        $requiredRoles = [self::ROLE_REVIEWER];

        // Utente è Manager + Reviewer
        // Manager è più elevato → Manager non nella lista → esente
        $this->assertFalse($this->userRequires2FA($requiredRoles, [self::ROLE_MANAGER, self::ROLE_REVIEWER]));

        // Utente è solo Reviewer → nella lista → richiede 2FA
        $this->assertTrue($this->userRequires2FA($requiredRoles, [self::ROLE_REVIEWER]));
    }

    // ── Caso reale dal database dell'utente admin-archi ───────────────────────

    public function testRealWorldAdminArchiScenario(): void
    {
        // Ruoli effettivi dell'utente admin-archi dal database
        $adminArchiRoles = [
            self::ROLE_SITE_ADMIN,  // 1
            self::ROLE_MANAGER,     // 16
            self::ROLE_REVIEWER,    // 4096
            self::ROLE_AUTHOR,      // 65536
            self::ROLE_READER,      // 1048576
        ];

        // Scenario: disabilito 2FA per Site Admin, attivo per tutti gli altri
        $requiredRoles = [
            self::ROLE_MANAGER,
            self::ROLE_REVIEWER,
            self::ROLE_AUTHOR,
            self::ROLE_READER,
        ];

        // admin-archi ha Site Admin come ruolo più elevato → esente
        $this->assertFalse($this->userRequires2FA($requiredRoles, $adminArchiRoles));

        // Un utente che è solo Author → richiede 2FA
        $this->assertTrue($this->userRequires2FA($requiredRoles, [self::ROLE_AUTHOR]));
    }
}
