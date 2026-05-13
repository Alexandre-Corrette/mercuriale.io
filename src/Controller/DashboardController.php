<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AlerteControleRepository;
use App\Twig\Extension\AppLayoutExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly AlerteControleRepository $alerteRepo,
        private readonly AppLayoutExtension $layoutExtension,
    ) {
    }

    #[Route('/app', name: 'app_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $etablissement = $this->layoutExtension->getSelectedEtablissement();
        $alerteCount = $etablissement !== null
            ? $this->alerteRepo->countNonTraiteesForEtablissement($etablissement)
            : 0;

        return $this->render('app/dashboard.html.twig', [
            'alerte_count' => $alerteCount,
        ]);
    }
}
