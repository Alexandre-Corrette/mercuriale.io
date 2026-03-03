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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
            'fournisseurs' => $this->fournisseurRepo->findWithProductCountForOrganisation($org),
            'crud_mercuriale_url' => $crudMercurialeUrl,
        ]);
    }

    #[Route('/admin/hub/mercuriale/search', name: 'admin_hub_mercuriale_search', methods: ['GET'])]
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
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $dateFrom = null;
        $dateFromStr = $request->query->getString('date_from', '');
        if ($dateFromStr !== '') {
            try {
                $dateFrom = new \DateTimeImmutable($dateFromStr);
            } catch (\Exception) {
            }
        }

        $dateTo = null;
        $dateToStr = $request->query->getString('date_to', '');
        if ($dateToStr !== '') {
            try {
                $dateTo = new \DateTimeImmutable($dateToStr);
            } catch (\Exception) {
            }
        }

        $result = $this->mercurialeRepo->searchForOrganisation(
            $org,
            $query !== '' ? $query : null,
            $fournisseurId,
            $dateFrom,
            $dateTo,
            $limit,
            $offset,
        );

        $items = array_map(fn ($m) => [
            'id' => $m->getId(),
            'designation' => $m->getProduitFournisseur()->getDesignationFournisseur(),
            'fournisseur' => $m->getProduitFournisseur()->getFournisseur()->getNom(),
            'prix' => $m->getPrixNegocieAsFloat(),
            'unite' => (string) $m->getProduitFournisseur()->getUniteAchat(),
            'dateDebut' => $m->getDateDebut()->format('d/m/Y'),
            'dateFin' => $m->getDateFin()?->format('d/m/Y'),
            'scope' => $m->getEtablissement() ? (string) $m->getEtablissement() : null,
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
