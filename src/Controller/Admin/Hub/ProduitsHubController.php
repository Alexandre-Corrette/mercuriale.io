<?php

declare(strict_types=1);

namespace App\Controller\Admin\Hub;

use App\Controller\Admin\ProduitFournisseurCrudController;
use App\Entity\Utilisateur;
use App\Repository\CategorieProduitRepository;
use App\Repository\FournisseurRepository;
use App\Repository\ProduitFournisseurRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class ProduitsHubController extends AbstractController
{
    public function __construct(
        private readonly ProduitFournisseurRepository $produitRepo,
        private readonly FournisseurRepository $fournisseurRepo,
        private readonly CategorieProduitRepository $categorieRepo,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[Route('/admin/produits', name: 'admin_hub_produits', methods: ['GET'])]
    public function index(): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        $crudProduitUrl = $this->adminUrlGenerator
            ->setController(ProduitFournisseurCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return $this->render('admin/hub/produits.html.twig', [
            'hub_title' => 'Produits',
            'produit_count' => $this->produitRepo->countActiveForOrganisation($org),
            'fournisseur_count' => $this->fournisseurRepo->countActiveForOrganisation($org),
            'fournisseurs' => $this->fournisseurRepo->findWithProductCountForOrganisation($org),
            'categories' => $this->categorieRepo->findWithProductCountForOrganisation($org),
            'crud_produit_url' => $crudProduitUrl,
        ]);
    }

    #[Route('/admin/produits/search', name: 'admin_hub_produits_search', methods: ['GET'])]
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

        $items = array_map(fn ($pf) => [
            'id' => $pf->getId(),
            'code' => $pf->getCodeFournisseur(),
            'designation' => $pf->getDesignationFournisseur(),
            'fournisseur' => $pf->getFournisseur()->getNom(),
            'categorie' => $pf->getProduit()?->getCategorie()?->getNom(),
            'conditionnement' => $pf->getConditionnementAsFloat(),
            'unite' => (string) $pf->getUniteAchat(),
        ], $result['items']);

        $pages = (int) ceil($result['total'] / $limit);

        return $this->json([
            'items' => $items,
            'total' => $result['total'],
            'page' => $page,
            'pages' => $pages,
        ]);
    }
}
