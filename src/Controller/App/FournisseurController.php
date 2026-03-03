<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\Fournisseur;
use App\Entity\Utilisateur;
use App\Repository\BonLivraisonRepository;
use App\Repository\FournisseurRepository;
use App\Repository\ProduitFournisseurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/fournisseurs')]
#[IsGranted('ROLE_USER')]
class FournisseurController extends AbstractController
{
    public function __construct(
        private readonly FournisseurRepository $fournisseurRepo,
    ) {
    }

    #[Route('', name: 'app_fournisseurs_hub', methods: ['GET'])]
    public function hub(): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        return $this->render('app/fournisseur/hub.html.twig', [
            'fournisseur_count' => $this->fournisseurRepo->countActiveForOrganisation($org),
        ]);
    }

    #[Route('/liste', name: 'app_fournisseurs_liste', methods: ['GET'])]
    public function liste(): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        return $this->render('app/fournisseur/liste.html.twig', [
            'fournisseurs' => $this->fournisseurRepo->findWithStatsForOrganisation($org),
            'fournisseur_count' => $this->fournisseurRepo->countActiveForOrganisation($org),
        ]);
    }

    #[Route('/{id}', name: 'app_fournisseur_show', methods: ['GET'])]
    public function show(
        Fournisseur $fournisseur,
        ProduitFournisseurRepository $produitRepo,
        BonLivraisonRepository $blRepo,
    ): Response {
        $this->denyAccessUnlessGranted('VIEW', $fournisseur);

        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        return $this->render('app/fournisseur/show.html.twig', [
            'fournisseur' => $fournisseur,
            'produits' => $produitRepo->findByFournisseur($fournisseur),
            'bons_livraison' => $blRepo->findRecentByFournisseurForOrganisation($fournisseur, $org),
        ]);
    }
}
