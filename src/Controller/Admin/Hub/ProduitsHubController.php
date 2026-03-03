<?php

declare(strict_types=1);

namespace App\Controller\Admin\Hub;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ProduitsHubController extends AbstractController
{
    #[Route('/admin/produits', name: 'admin_hub_produits', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_produits_hub', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/admin/produits/search', name: 'admin_hub_produits_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        return $this->redirectToRoute('app_produits_search', $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
    }
}
