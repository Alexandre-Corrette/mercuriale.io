<?php

declare(strict_types=1);

namespace App\Controller\Admin\Hub;

use App\Controller\Admin\MercurialeCrudController;
use App\Entity\Utilisateur;
use App\Repository\FournisseurRepository;
use App\Repository\MercurialeRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class MercurialeHubController extends AbstractController
{
    public function __construct(
        private readonly MercurialeRepository $mercurialeRepo,
        private readonly FournisseurRepository $fournisseurRepo,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[Route('/admin/mercuriale', name: 'admin_hub_mercuriale', methods: ['GET'])]
    public function index(): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        $crudMercurialeUrl = $this->adminUrlGenerator
            ->setController(MercurialeCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return $this->render('admin/hub/mercuriale.html.twig', [
            'hub_title' => 'Mercuriale',
            'mercuriale_count' => $this->mercurialeRepo->countActiveForOrganisation($org),
            'fournisseur_count' => $this->fournisseurRepo->countActiveForOrganisation($org),
            'recent_mercuriales' => $this->mercurialeRepo->findRecentForOrganisation($org),
            'crud_mercuriale_url' => $crudMercurialeUrl,
        ]);
    }
}
