<?php

declare(strict_types=1);

namespace App\Controller\Admin\Hub;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class BonLivraisonHubController extends AbstractController
{
    #[Route('/admin/bl', name: 'admin_hub_bl', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_bl_hub', [], Response::HTTP_MOVED_PERMANENTLY);
    }
}
