<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\Utilisateur;
use App\Service\TaxService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/fournisseurs/taxes')]
#[IsGranted('ROLE_GERANT')]
#[IsGranted('VERIFIED_FEATURE')]
class TaxController extends AbstractController
{
    public function __construct(
        private readonly TaxService $taxService,
    ) {
    }

    #[Route('', name: 'app_taxes_tva', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        if ($org === null) {
            throw $this->createAccessDeniedException();
        }

        $year = $request->query->getInt('year', (int) date('Y'));
        $month = $request->query->getInt('month', (int) date('m'));
        $periodicity = $request->query->get('periodicity', 'monthly');

        if (!\in_array($periodicity, ['monthly', 'quarterly'], true)) {
            $periodicity = 'monthly';
        }

        $data = $this->taxService->computeVatForPeriod($org, $year, $month, $periodicity);

        return $this->render('app/facture/taxes.html.twig', [
            'data' => $data,
            'year' => $year,
            'month' => $month,
            'periodicity' => $periodicity,
        ]);
    }
}
