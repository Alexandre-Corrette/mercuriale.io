<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\BonLivraison;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/bons-livraison')]
#[IsGranted('ROLE_USER')]
class BonLivraisonConsultationController extends AbstractController
{
    #[Route('', name: 'app_bl_list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('app/bon_livraison/list.html.twig');
    }

    #[Route('/{id}', name: 'app_bl_detail', methods: ['GET'])]
    public function detail(BonLivraison $bonLivraison): Response
    {
        if (!$this->isGranted('VIEW', $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('app/bon_livraison/detail.html.twig', [
            'blId' => $bonLivraison->getId(),
        ]);
    }
}
