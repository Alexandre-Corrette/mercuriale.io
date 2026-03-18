<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Etablissement;
use App\Entity\Organisation;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurOrganisation;
use App\Exception\NoActiveOrganisationException;
use App\Exception\OrganisationAccessRevokedException;
use App\Repository\EtablissementRepository;
use App\Repository\OrganisationRepository;
use App\Repository\UtilisateurOrganisationRepository;
use App\Service\OrganisationContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class OrganisationContextTest extends TestCase
{
    private MockObject&Security $security;
    private MockObject&OrganisationRepository $orgRepo;
    private MockObject&EtablissementRepository $etabRepo;
    private MockObject&UtilisateurOrganisationRepository $uoRepo;
    private Session $session;
    private OrganisationContext $context;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->orgRepo = $this->createMock(OrganisationRepository::class);
        $this->etabRepo = $this->createMock(EtablissementRepository::class);
        $this->uoRepo = $this->createMock(UtilisateurOrganisationRepository::class);

        $this->session = new Session(new MockArraySessionStorage());
        $requestStack = new RequestStack();
        $request = new Request();
        $request->setSession($this->session);
        $requestStack->push($request);

        $this->context = new OrganisationContext(
            $requestStack,
            $this->security,
            $this->orgRepo,
            $this->etabRepo,
            $this->uoRepo,
        );
    }

    // ─── getActiveOrganisation ──────────────────────────────────

    public function testMonoOrgAutoSelectsWithoutSession(): void
    {
        $user = $this->createUser();
        $org = $this->createOrg(1, 'Escale');
        $uo = $this->createUo($user, $org);

        $this->security->method('getUser')->willReturn($user);
        $this->uoRepo->method('findByUtilisateur')->willReturn([$uo]);

        $result = $this->context->getActiveOrganisation();

        self::assertSame($org, $result);
        self::assertSame(1, $this->session->get(OrganisationContext::SESSION_ORG_KEY));
    }

    public function testMultiOrgWithoutSelectionThrows(): void
    {
        $user = $this->createUser();
        $orgA = $this->createOrg(1, 'Org A');
        $orgB = $this->createOrg(2, 'Org B');

        $this->security->method('getUser')->willReturn($user);
        $this->uoRepo->method('findByUtilisateur')->willReturn([
            $this->createUo($user, $orgA),
            $this->createUo($user, $orgB),
        ]);

        $this->expectException(NoActiveOrganisationException::class);
        $this->context->getActiveOrganisation();
    }

    public function testSelectedOrgIsReturnedFromSession(): void
    {
        $user = $this->createUser();
        $org = $this->createOrg(42, 'Test Org');
        $uo = $this->createUo($user, $org);

        $this->session->set(OrganisationContext::SESSION_ORG_KEY, 42);
        $this->security->method('getUser')->willReturn($user);
        $this->orgRepo->method('find')->with(42)->willReturn($org);
        $this->uoRepo->method('findOneByUtilisateurAndOrganisation')
            ->with($user, $org)->willReturn($uo);

        $result = $this->context->getActiveOrganisation();

        self::assertSame($org, $result);
    }

    public function testRevokedAccessClearsSessionAndThrows(): void
    {
        $user = $this->createUser();
        $org = $this->createOrg(42, 'Revoked Org');

        $this->session->set(OrganisationContext::SESSION_ORG_KEY, 42);
        $this->security->method('getUser')->willReturn($user);
        $this->orgRepo->method('find')->with(42)->willReturn($org);
        // User no longer a member
        $this->uoRepo->method('findOneByUtilisateurAndOrganisation')
            ->with($user, $org)->willReturn(null);

        $this->expectException(OrganisationAccessRevokedException::class);
        $this->context->getActiveOrganisation();
    }

    public function testDeletedOrgClearsSessionAndThrows(): void
    {
        $user = $this->createUser();

        $this->session->set(OrganisationContext::SESSION_ORG_KEY, 999);
        $this->security->method('getUser')->willReturn($user);
        $this->orgRepo->method('find')->with(999)->willReturn(null);

        $this->expectException(OrganisationAccessRevokedException::class);
        $this->context->getActiveOrganisation();
    }

    // ─── isMultiOrganisation ────────────────────────────────────

    public function testIsMultiOrgTrueForTwoOrgs(): void
    {
        $user = $this->createUser();
        $this->security->method('getUser')->willReturn($user);
        $this->uoRepo->method('findByUtilisateur')->willReturn([
            $this->createUo($user, $this->createOrg(1, 'A')),
            $this->createUo($user, $this->createOrg(2, 'B')),
        ]);

        self::assertTrue($this->context->isMultiOrganisation());
    }

    public function testIsMultiOrgFalseForOneOrg(): void
    {
        $user = $this->createUser();
        $this->security->method('getUser')->willReturn($user);
        $this->uoRepo->method('findByUtilisateur')->willReturn([
            $this->createUo($user, $this->createOrg(1, 'A')),
        ]);

        self::assertFalse($this->context->isMultiOrganisation());
    }

    // ─── getUserOrganisations ───────────────────────────────────

    public function testGetUserOrganisationsSortedByName(): void
    {
        $user = $this->createUser();
        $orgZ = $this->createOrg(1, 'Zinc');
        $orgA = $this->createOrg(2, 'Alpha');

        $this->security->method('getUser')->willReturn($user);
        $this->uoRepo->method('findByUtilisateur')->willReturn([
            $this->createUo($user, $orgZ),
            $this->createUo($user, $orgA),
        ]);

        $result = $this->context->getUserOrganisations();

        self::assertCount(2, $result);
        self::assertSame('Alpha', $result[0]->getNom());
        self::assertSame('Zinc', $result[1]->getNom());
    }

    // ─── switchContext ───────────────────────────────────────────

    public function testSwitchContextWritesBothSessionKeys(): void
    {
        $this->context->switchContext(10, 20);

        self::assertSame(10, $this->session->get(OrganisationContext::SESSION_ORG_KEY));
        self::assertSame(20, $this->session->get('_selected_etablissement_id'));
    }

    // ─── No user ────────────────────────────────────────────────

    public function testNoUserThrowsNoActiveOrganisation(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(NoActiveOrganisationException::class);
        $this->context->getActiveOrganisation();
    }

    // ─── Helpers ────────────────────────────────────────────────

    private function createUser(): Utilisateur
    {
        $user = $this->createMock(Utilisateur::class);
        $user->method('getId')->willReturn(1);

        return $user;
    }

    private function createOrg(int $id, string $nom): Organisation
    {
        $org = $this->createMock(Organisation::class);
        $org->method('getId')->willReturn($id);
        $org->method('getNom')->willReturn($nom);

        return $org;
    }

    private function createUo(Utilisateur $user, Organisation $org): UtilisateurOrganisation
    {
        $uo = $this->createMock(UtilisateurOrganisation::class);
        $uo->method('getUtilisateur')->willReturn($user);
        $uo->method('getOrganisation')->willReturn($org);
        $uo->method('getRole')->willReturn('owner');

        return $uo;
    }
}
