<?php

declare(strict_types=1);

namespace App\Controller\App;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/pending')]
#[IsGranted('ROLE_USER')]
class PendingController extends AbstractController
{
    #[Route('', name: 'app_pending', methods: ['GET'])]
    public function index(): Response
    {
        // Cette page est 100% côté client (IndexedDB)
        // Le serveur ne fait que servir le template
        return $this->render('app/pending/index.html.twig');
    }
}