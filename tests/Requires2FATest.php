<?php

namespace APP\plugins\generic\loginOtp\tests;

use PHPUnit\Framework\TestCase;

/**
 * Testa la logica di determinazione della 2FA (userRequires2FA).
 *
 * Replica la logica pura del plugin senza dipendenze da OJS/DB.
 * Sostituisce il precedente RoleHierarchyTest: la logica non è più
 * a gerarchia ma OR (più restrittiva).
 *
 * Regole, in ordine di precedenza:
 *  1. Login a livello sito → sempre 2FA
 *  2. Site Admin → sempre 2FA
 *  3. Impostazione non configurata (null) → default sicuro: 2FA
 *  4. Logica OR: se almeno un ruolo dell'utente nella rivista è
 *     tra i requiredRoles → 2FA
 *
 * I valori delle costanti Role::ROLE_ID_* sono i bitmask standard PKP.
 * Con la logica OR l'ordine dei ruoli è irrilevante (nessuna gerarchia):
 * conta solo l'appartenenza all'insieme.
 */
class Requires2FATest extends TestCase
{
    private const ROLE_SITE_ADMIN       = 1;
    private const ROLE_MANAGER          = 16;
    private const ROLE_SUB_EDITOR       = 17;
    private const ROLE_ASSISTANT        = 4097;
    private const ROLE_REVIEWER         = 4096;
    private const ROLE_AUTHOR           = 65536;
    private const ROLE_READER           = 1048576;
    private const ROLE_SUBSCRIPTION_MGR = 2097152;

    /**
     * Replica della logica pura di userRequires2FA().
     *
     * @param bool       $isSiteContext   login a livello sito (nessuna rivista)
     * @param bool       $userIsSiteAdmin l'utente ha il ruolo Site Admin
     * @param array|null $requiredRoles   ruoli soggetti a 2FA per la rivista (null = non configurato)
     * @param int[]      $userRoleIds     ruoli dell'utente nella rivista corrente
     */
    private function requires2FA(
        bool $isSiteContext,
        bool $userIsSiteAdmin,
        ?array $requiredRoles,
        array $userRoleIds
    ): bool {
        // Regola 1: login di sito → sempre 2FA
        if ($isSiteContext) {
            return true;
        }

        // Regola 2: Site Admin → sempre 2FA
        if ($userIsSiteAdmin) {
            return true;
        }

        // Regola 3: non configurato → default sicuro
        if ($requiredRoles === null) {
            return true;
        }

        // Regola 4: logica OR
        return !empty(array_intersect($requiredRoles, $userRoleIds));
    }

    // ── Regola 1: login di sito → sempre 2FA ──────────────────────────────────

    public function testSiteLoginAlwaysRequires2FA(): void
    {
        // Indipendentemente da ruoli e impostazioni
        $this->assertTrue($this->requires2FA(true, false, [], [self::ROLE_READER]));
        $this->assertTrue($this->requires2FA(true, false, [], []));
        $this->assertTrue($this->requires2FA(true, false, null, [self::ROLE_AUTHOR]));
    }

    // ── Regola 2: Site Admin → sempre 2FA ─────────────────────────────────────

    public function testSiteAdminAlwaysRequires2FA(): void
    {
        // Anche in contesto rivista, anche se i requiredRoles della rivista sono vuoti
        $this->assertTrue($this->requires2FA(false, true, [], [self::ROLE_MANAGER]));
        $this->assertTrue($this->requires2FA(false, true, null, [self::ROLE_MANAGER]));
        $this->assertTrue($this->requires2FA(false, true, [self::ROLE_AUTHOR], [self::ROLE_READER]));
    }

    // ── Regola 3: non configurato → default sicuro ────────────────────────────

    public function testNullSettingRequires2FA(): void
    {
        $this->assertTrue($this->requires2FA(false, false, null, [self::ROLE_AUTHOR]));
        $this->assertTrue($this->requires2FA(false, false, null, []));
    }

    // ── Regola 4: requiredRoles vuoti → il manager ha disattivato l'OTP ───────

    public function testEmptyRolesDisables2FAForJournal(): void
    {
        // Un journal manager che svuota i requiredRoles disattiva l'OTP per la sua rivista
        $this->assertFalse($this->requires2FA(false, false, [], [self::ROLE_MANAGER, self::ROLE_READER]));
        $this->assertFalse($this->requires2FA(false, false, [], [self::ROLE_AUTHOR]));
    }

    // ── Logica OR: almeno un ruolo soggetto → 2FA ─────────────────────────────

    public function testOrLogicAnyMatchingRoleTriggers2FA(): void
    {
        $requiredRoles = [self::ROLE_REVIEWER];

        // Utente Manager + Reviewer: Reviewer è richiesto → 2FA
        // (con la vecchia gerarchia sarebbe stato ESENTE: Manager più alto, non richiesto)
        $this->assertTrue($this->requires2FA(false, false, $requiredRoles, [self::ROLE_MANAGER, self::ROLE_REVIEWER]));

        // Solo Reviewer → 2FA
        $this->assertTrue($this->requires2FA(false, false, $requiredRoles, [self::ROLE_REVIEWER]));
    }

    public function testOrLogicNoMatchingRoleExempt(): void
    {
        $requiredRoles = [self::ROLE_MANAGER];

        // Utente Author + Reader, nessuno dei due richiesto → esente
        $this->assertFalse($this->requires2FA(false, false, $requiredRoles, [self::ROLE_AUTHOR, self::ROLE_READER]));
    }

    public function testOrLogicSingleRoleMatch(): void
    {
        $requiredRoles = [self::ROLE_AUTHOR, self::ROLE_REVIEWER];

        $this->assertTrue($this->requires2FA(false, false, $requiredRoles, [self::ROLE_AUTHOR]));
        $this->assertFalse($this->requires2FA(false, false, $requiredRoles, [self::ROLE_READER]));
    }

    // ── Contrasto esplicito con la vecchia logica a gerarchia ─────────────────

    public function testContrastWithOldHierarchyLogic(): void
    {
        // Scenario che con la gerarchia dava ESENTE e ora con OR dà 2FA:
        // solo Reviewer è richiesto, utente è Manager + Reviewer.
        $requiredRoles = [self::ROLE_REVIEWER];
        $userRoles = [self::ROLE_MANAGER, self::ROLE_REVIEWER];

        // Vecchia logica (gerarchia): Manager più alto, non richiesto → false
        // Nuova logica (OR): Reviewer richiesto → true
        $this->assertTrue($this->requires2FA(false, false, $requiredRoles, $userRoles));
    }

    // ── Caso reale admin-archi: ora sempre 2FA perché Site Admin ──────────────

    public function testRealWorldAdminArchiNowAlwaysRequires2FA(): void
    {
        // admin-archi ha il ruolo Site Admin → Regola 2 → sempre 2FA,
        // indipendentemente dai requiredRoles della rivista.
        $requiredRoles = [self::ROLE_MANAGER, self::ROLE_AUTHOR, self::ROLE_READER];

        $this->assertTrue($this->requires2FA(false, true, $requiredRoles, [
            self::ROLE_MANAGER, self::ROLE_REVIEWER, self::ROLE_AUTHOR, self::ROLE_READER,
        ]));

        // Un utente che è solo Author, con Author richiesto → 2FA
        $this->assertTrue($this->requires2FA(false, false, $requiredRoles, [self::ROLE_AUTHOR]));
    }
}
