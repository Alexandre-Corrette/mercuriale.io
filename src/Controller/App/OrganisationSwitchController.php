<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\Utilisateur;
use App\Repository\EtablissementRepository;
use App\Repository\OrganisationRepository;
use App\Service\OrganisationContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class OrganisationSwitchController extends AbstractController
{
    public function __construct(
        private readonly OrganisationContext $organisationContext,
        private readonly OrganisationRepository $organisationRepository,
        private readonly EtablissementRepository $etablissementRepository,
    ) {
    }

    #[Route('/app/select-organisation', name: 'app_select_organisation', methods: ['GET'])]
    public function selectOrganisation(): Response
    {
        $organisations = $this->organisationContext->getUserOrganisations();

        // Auto-select if only 1 org
        if (\count($organisations) === 1) {
            $org = $organisations[0];
            $etabs = $org->getEtablissements();
            $firstEtab = $etabs->first();

            if ($firstEtab) {
                $this->organisationContext->switchContext($org->getId(), $firstEtab->getId());
            }

            return $this->redirectToRoute('admin');
        }

        // No org at all — shouldn't happen but handle gracefully
        if (\count($organisations) === 0) {
            $this->addFlash('info', 'Vous n\'êtes rattaché à aucune société. Veuillez en créer une.');

            return $this->redirectToRoute('app_register_step3');
        }

        return $this->render('app/select_organisation.html.twig', [
            'organisations' => $organisations,
        ]);
    }

    #[Route('/app/switch-context', name: 'app_switch_context', methods: ['POST'])]
    public function switchContext(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('switch_context', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');

            return $this->redirectToRoute('app_select_organisation');
        }

        $organisationId = $request->request->getInt('organisation_id');
        $etablissementId = $request->request->getInt('etablissement_id');

        // Validate organisation exists and user has access
        $organisation = $this->organisationRepository->find($organisationId);
        if ($organisation === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted('ORG_VIEW', $organisation);

        // Validate etablissement exists and belongs to org
        $etablissement = $this->etablissementRepository->find($etablissementId);
        if ($etablissement === null || $etablissement->getOrganisation()?->getId() !== $organisation->getId()) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted('VIEW', $etablissement);

        $this->organisationContext->switchContext($organisationId, $etablissementId);

        // Redirect back or to dashboard
        $referer = $request->headers->get('referer');
        if ($referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('admin');
    }
}
