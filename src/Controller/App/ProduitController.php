<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\ProduitFournisseur;
use App\Entity\Utilisateur;
use App\Repository\CategorieProduitRepository;
use App\Repository\FournisseurRepository;
use App\Repository\LigneBonLivraisonRepository;
use App\Repository\ProduitFournisseurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/produits')]
#[IsGranted('ROLE_USER')]
class ProduitController extends AbstractController
{
    public function __construct(
        private readonly ProduitFournisseurRepository $produitRepo,
        private readonly FournisseurRepository $fournisseurRepo,
        private readonly CategorieProduitRepository $categorieRepo,
    ) {
    }

    #[Route('', name: 'app_produits_hub', methods: ['GET'])]
    public function hub(): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        return $this->render('app/produit/hub.html.twig', [
            'produit_count' => $this->produitRepo->countActiveForOrganisation($org),
            'fournisseur_count' => $this->fournisseurRepo->countActiveForOrganisation($org),
        ]);
    }

    #[Route('/liste', name: 'app_produits_liste', methods: ['GET'])]
    public function liste(): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        return $this->render('app/produit/liste.html.twig', [
            'produit_count' => $this->produitRepo->countActiveForOrganisation($org),
            'fournisseur_count' => $this->fournisseurRepo->countActiveForOrganisation($org),
            'fournisseurs' => $this->fournisseurRepo->findWithProductCountForOrganisation($org),
            'categories' => $this->categorieRepo->findWithProductCountForOrganisation($org),
        ]);
    }

    #[Route('/liste/search', name: 'app_produits_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        $query = $request->query->getString('q', '');
        if (mb_strlen($query) > 100) {
            $query = mb_substr($query, 0, 100);
        }

        $fournisseurId = $request->query->getInt('fournisseur') ?: null;
        $categorieId = $request->query->getInt('categorie') ?: null;
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $result = $this->produitRepo->searchForOrganisation(
            $org,
            $query !== '' ? $query : null,
            $fournisseurId,
            $categorieId,
            $limit,
            $offset,
        );

        $items = array_map(fn (ProduitFournisseur $pf) => [
            'id' => $pf->getId(),
            'code' => $pf->getCodeFournisseur(),
            'designation' => $pf->getDesignationFournisseur(),
            'fournisseur' => $pf->getFournisseur()->getNom(),
            'categorie' => $pf->getProduit()?->getCategorie()?->getNom(),
            'conditionnement' => $pf->getConditionnementAsFloat(),
            'unite' => (string) $pf->getUniteAchat(),
            'url' => $this->generateUrl('app_produit_show', ['id' => $pf->getId()]),
        ], $result['items']);

        $pages = (int) ceil($result['total'] / $limit);

        return $this->json([
            'items' => $items,
            'total' => $result['total'],
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    #[Route('/{id}', name: 'app_produit_show', methods: ['GET'])]
    public function show(
        ProduitFournisseur $produitFournisseur,
        LigneBonLivraisonRepository $ligneBLRepo,
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        // Vérifier que le produit appartient à un fournisseur de l'organisation
        $fournisseur = $produitFournisseur->getFournisseur();
        $belongsToOrg = false;
        foreach ($fournisseur->getOrganisationFournisseurs() as $orgFournisseur) {
            if ($orgFournisseur->getOrganisation() === $org && $orgFournisseur->isActif()) {
                $belongsToOrg = true;
                break;
            }
        }

        if (!$belongsToOrg) {
            throw $this->createAccessDeniedException();
        }

        $recentLignes = $ligneBLRepo->findRecentByProduitFournisseur($produitFournisseur);

        return $this->render('app/produit/show.html.twig', [
            'produit' => $produitFournisseur,
            'recent_lignes' => $recentLignes,
        ]);
    }
}
