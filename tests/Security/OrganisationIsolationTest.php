<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\Organisation;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurOrganisation;
use App\Repository\OrganisationFournisseurRepository;
use App\Repository\UtilisateurEtablissementRepository;
use App\Repository\UtilisateurOrganisationRepository;
use App\Security\Voter\AssignFournisseurToBonLivraisonVoter;
use App\Security\Voter\EtablissementVoter;
use App\Security\Voter\FournisseurVoter;
use App\Security\Voter\OrganisationVoter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Tests that Voters correctly isolate data between organisations.
 *
 * Scenario:
 *   User U is owner of Org A and Org B
 *   Etab A1 belongs to Org A
 *   Etab B1 belongs to Org B
 *   User U should NOT be able to use Org A's fournisseur on Org B's BL
 */
class OrganisationIsolationTest extends TestCase
{
    private Organisation $orgA;
    private Organisation $orgB;
    private Etablissement $etabA1;
    private Etablissement $etabB1;
    private Utilisateur $userU;

    protected function setUp(): void
    {
        $this->orgA = $this->createOrg(1, 'Org A');
        $this->orgB = $this->createOrg(2, 'Org B');
        $this->etabA1 = $this->createEtab(10, $this->orgA);
        $this->etabB1 = $this->createEtab(20, $this->orgB);
        $this->userU = $this->createUser(100, ['ROLE_ADMIN']);
    }

    // ─── OrganisationVoter ─────────────────────────────────────

    public function testOrgVoterGrantsAccessToOwnOrg(): void
    {
        $uoRepo = $this->createMock(UtilisateurOrganisationRepository::class);
        $uoRepo->method('findOneByUtilisateurAndOrganisation')
            ->with($this->userU, $this->orgA)
            ->willReturn($this->createUo($this->userU, $this->orgA));

        $voter = new OrganisationVoter($uoRepo);
        $token = $this->tokenFor($this->userU);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $this->orgA, ['ORG_VIEW']));
    }

    public function testOrgVoterDeniesAccessToForeignOrg(): void
    {
        $uoRepo = $this->createMock(UtilisateurOrganisationRepository::class);
        $uoRepo->method('findOneByUtilisateurAndOrganisation')
            ->willReturn(null); // not a member of this org

        $voter = new OrganisationVoter($uoRepo);
        $token = $this->tokenFor($this->userU);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $this->orgB, ['ORG_VIEW']));
    }

    // ─── EtablissementVoter ────────────────────────────────────

    public function testEtabVoterGrantsAccessWhenUserIsMemberOfEtabOrg(): void
    {
        $ueRepo = $this->createMock(UtilisateurEtablissementRepository::class);
        $uoRepo = $this->createMock(UtilisateurOrganisationRepository::class);
        $uoRepo->method('findOneByUtilisateurAndOrganisation')
            ->with($this->userU, $this->orgA)
            ->willReturn($this->createUo($this->userU, $this->orgA));

        $voter = new EtablissementVoter($ueRepo, $uoRepo);
        $token = $this->tokenFor($this->userU);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $this->etabA1, ['VIEW']));
    }

    public function testEtabVoterDeniesAccessWhenUserIsNotMemberOfEtabOrg(): void
    {
        $ueRepo = $this->createMock(UtilisateurEtablissementRepository::class);
        $uoRepo = $this->createMock(UtilisateurOrganisationRepository::class);
        $uoRepo->method('findOneByUtilisateurAndOrganisation')
            ->with($this->userU, $this->orgB)
            ->willReturn(null); // not a member of org B

        $voter = new EtablissementVoter($ueRepo, $uoRepo);
        $token = $this->tokenFor($this->userU);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $this->etabB1, ['VIEW']));
    }

    // ─── AssignFournisseurToBonLivraisonVoter ──────────────────

    public function testAssignVoterDeniesCrossOrgFournisseurAssignment(): void
    {
        $bl = $this->createMock(\App\Entity\BonLivraison::class);
        $bl->method('getEtablissement')->willReturn($this->etabA1); // BL in Org A

        $fournisseur = $this->createMock(Fournisseur::class);

        $orgFournisseurRepo = $this->createMock(OrganisationFournisseurRepository::class);
        // Fournisseur NOT linked to Org A
        $orgFournisseurRepo->method('hasAccess')
            ->with($this->orgA, $fournisseur)
            ->willReturn(false);

        $voter = new AssignFournisseurToBonLivraisonVoter($orgFournisseurRepo);
        $token = $this->tokenFor($this->userU);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, [$fournisseur, $bl], ['ASSIGN_TO_BL'])
        );
    }

    public function testAssignVoterGrantsSameOrgFournisseurAssignment(): void
    {
        $bl = $this->createMock(\App\Entity\BonLivraison::class);
        $bl->method('getEtablissement')->willReturn($this->etabA1);

        $fournisseur = $this->createMock(Fournisseur::class);

        $orgFournisseurRepo = $this->createMock(OrganisationFournisseurRepository::class);
        $orgFournisseurRepo->method('hasAccess')
            ->with($this->orgA, $fournisseur)
            ->willReturn(true);

        $voter = new AssignFournisseurToBonLivraisonVoter($orgFournisseurRepo);
        $token = $this->tokenFor($this->userU);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, [$fournisseur, $bl], ['ASSIGN_TO_BL'])
        );
    }

    // ─── Cross-org scenario: user member of both orgs ──────────

    public function testUserMemberOfBothOrgsCannotCrossAssign(): void
    {
        // User is member of BOTH org A and org B
        // BL belongs to Org A
        // Fournisseur belongs to Org B ONLY
        $bl = $this->createMock(\App\Entity\BonLivraison::class);
        $bl->method('getEtablissement')->willReturn($this->etabA1);

        $fournisseur = $this->createMock(Fournisseur::class);

        $orgFournisseurRepo = $this->createMock(OrganisationFournisseurRepository::class);
        // Fournisseur linked to Org B but NOT to Org A
        $orgFournisseurRepo->method('hasAccess')
            ->willReturnCallback(function (Organisation $org, Fournisseur $f) {
                return $org->getId() === 2; // only linked to Org B
            });

        $voter = new AssignFournisseurToBonLivraisonVoter($orgFournisseurRepo);
        $token = $this->tokenFor($this->userU);

        // The voter checks against the BL's org (A), where fournisseur has no access
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($token, [$fournisseur, $bl], ['ASSIGN_TO_BL'])
        );
    }

    // ─── Helpers ────────────────────────────────────────────────

    private function createOrg(int $id, string $nom): Organisation
    {
        $org = $this->createMock(Organisation::class);
        $org->method('getId')->willReturn($id);
        $org->method('getNom')->willReturn($nom);

        return $org;
    }

    private function createEtab(int $id, Organisation $org): Etablissement
    {
        $etab = $this->createMock(Etablissement::class);
        $etab->method('getId')->willReturn($id);
        $etab->method('getOrganisation')->willReturn($org);

        return $etab;
    }

    private function createUser(int $id, array $roles): Utilisateur
    {
        $user = $this->createMock(Utilisateur::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRoles')->willReturn($roles);

        return $user;
    }

    private function createUo(Utilisateur $user, Organisation $org): UtilisateurOrganisation
    {
        $uo = $this->createMock(UtilisateurOrganisation::class);
        $uo->method('getUtilisateur')->willReturn($user);
        $uo->method('getOrganisation')->willReturn($org);
        $uo->method('getRole')->willReturn('owner');

        return $uo;
    }

    private function tokenFor(Utilisateur $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
