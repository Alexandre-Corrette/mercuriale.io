<?php

declare(strict_types=1);

namespace App\Controller\Admin\Hub;

use App\Controller\Admin\BonLivraisonCrudController;
use App\Entity\Utilisateur;
use App\Repository\AlerteControleRepository;
use App\Repository\BonLivraisonRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class BonLivraisonHubController extends AbstractController
{
    public function __construct(
        private readonly BonLivraisonRepository $blRepo,
        private readonly AlerteControleRepository $alerteRepo,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[Route('/admin/bl', name: 'admin_hub_bl', methods: ['GET'])]
    public function index(): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        $crudBlUrl = $this->adminUrlGenerator
            ->setController(BonLivraisonCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return $this->render('admin/hub/bon_livraison.html.twig', [
            'hub_title' => 'Bons de livraison',
            'bl_count' => $this->blRepo->countByMonthForOrganisation($org),
            'alerte_count' => $this->alerteRepo->countNonTraiteesForOrganisation($org),
            'recent_bls' => $this->blRepo->findRecentWithAlertCountForOrganisation($org),
            'crud_bl_url' => $crudBlUrl,
        ]);
    }
}
