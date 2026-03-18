<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\BonLivraison;
use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\Organisation;
use App\Entity\Utilisateur;
use App\Repository\OrganisationFournisseurRepository;
use App\Security\Voter\AssignFournisseurToBonLivraisonVoter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class AssignFournisseurToBonLivraisonVoterTest extends TestCase
{
    private MockObject&OrganisationFournisseurRepository $orgFournisseurRepo;
    private AssignFournisseurToBonLivraisonVoter $voter;

    protected function setUp(): void
    {
        $this->orgFournisseurRepo = $this->createMock(OrganisationFournisseurRepository::class);
        $this->voter = new AssignFournisseurToBonLivraisonVoter($this->orgFournisseurRepo);
    }

    public function testSameOrganisationGrantsAccess(): void
    {
        $org = $this->createOrganisation(1);
        $fournisseur = $this->createMock(Fournisseur::class);
        $bl = $this->createBonLivraisonWithOrg($org);
        $token = $this->createTokenForUser($org);

        $this->orgFournisseurRepo->method('hasAccess')
            ->with($org, $fournisseur)
            ->willReturn(true);

        $result = $this->voter->vote($token, [$fournisseur, $bl], ['ASSIGN_TO_BL']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testDifferentOrganisationDeniesAccess(): void
    {
        $orgA = $this->createOrganisation(1);
        $orgB = $this->createOrganisation(2);
        $fournisseur = $this->createMock(Fournisseur::class);
        $bl = $this->createBonLivraisonWithOrg($orgA);
        $token = $this->createTokenForUser($orgB);

        // Fournisseur belongs to org B, not org A (the BL's org)
        $this->orgFournisseurRepo->method('hasAccess')
            ->with($orgA, $fournisseur)
            ->willReturn(false);

        $result = $this->voter->vote($token, [$fournisseur, $bl], ['ASSIGN_TO_BL']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testBonLivraisonWithoutEtablissementDeniesAccess(): void
    {
        $org = $this->createOrganisation(1);
        $fournisseur = $this->createMock(Fournisseur::class);
        $token = $this->createTokenForUser($org);

        $bl = $this->createMock(BonLivraison::class);
        $bl->method('getEtablissement')->willReturn(null);

        $result = $this->voter->vote($token, [$fournisseur, $bl], ['ASSIGN_TO_BL']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAbstainsOnWrongAttribute(): void
    {
        $fournisseur = $this->createMock(Fournisseur::class);
        $bl = $this->createMock(BonLivraison::class);
        $token = $this->createTokenForUser($this->createOrganisation(1));

        $result = $this->voter->vote($token, [$fournisseur, $bl], ['VIEW']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testAbstainsOnWrongSubjectType(): void
    {
        $token = $this->createTokenForUser($this->createOrganisation(1));

        $result = $this->voter->vote($token, 'not-an-array', ['ASSIGN_TO_BL']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    // ─── Helpers ────────────────────────────────────────────────

    private function createOrganisation(int $id): Organisation
    {
        $org = $this->createMock(Organisation::class);
        $org->method('getId')->willReturn($id);

        return $org;
    }

    private function createBonLivraisonWithOrg(Organisation $org): BonLivraison
    {
        $etab = $this->createMock(Etablissement::class);
        $etab->method('getOrganisation')->willReturn($org);

        $bl = $this->createMock(BonLivraison::class);
        $bl->method('getEtablissement')->willReturn($etab);

        return $bl;
    }

    private function createTokenForUser(Organisation $org): UsernamePasswordToken
    {
        $user = $this->createMock(Utilisateur::class);
        $user->method('getOrganisation')->willReturn($org);
        $user->method('getRoles')->willReturn(['ROLE_ADMIN']);

        return new UsernamePasswordToken($user, 'main', ['ROLE_ADMIN']);
    }
}
