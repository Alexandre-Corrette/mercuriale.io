<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\BonLivraison;
use App\Entity\Utilisateur;
use App\Repository\AlerteControleRepository;
use App\Repository\BonLivraisonRepository;
use App\Service\BonLivraisonImageService;
use App\Twig\Extension\AppLayoutExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/bl')]
#[IsGranted('ROLE_USER')]
class BonLivraisonController extends AbstractController
{
    public function __construct(
        private readonly BonLivraisonImageService $imageService,
    ) {
    }

    #[Route('', name: 'app_bl_hub', methods: ['GET'])]
    public function hub(
        BonLivraisonRepository $blRepo,
        AlerteControleRepository $alerteRepo,
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        return $this->render('app/bon_livraison/hub.html.twig', [
            'pending_count' => $blRepo->countAnomalieForOrganisation($org),
            'alerte_count' => $alerteRepo->countNonTraiteesForOrganisation($org),
        ]);
    }

    #[Route('/liste', name: 'app_bl_liste', methods: ['GET'])]
    public function liste(
        BonLivraisonRepository $blRepo,
        AppLayoutExtension $layoutExtension,
    ): Response {
        $etablissement = $layoutExtension->getSelectedEtablissement();
        if (!$etablissement) {
            throw $this->createAccessDeniedException();
        }
        $this->denyAccessUnlessGranted('VIEW', $etablissement);

        $blData = $blRepo->findValidatedByEtablissementWithAlertCount($etablissement);

        return $this->render('app/bon_livraison/liste.html.twig', [
            'bons_livraison' => $blData,
            'bl_count' => count($blData),
            'etablissement' => $etablissement,
        ]);
    }

    #[Route('/alertes', name: 'app_bl_alertes', methods: ['GET'])]
    public function alertes(
        AlerteControleRepository $alerteRepo,
        AppLayoutExtension $layoutExtension,
    ): Response {
        $etablissement = $layoutExtension->getSelectedEtablissement();
        if (!$etablissement) {
            throw $this->createAccessDeniedException();
        }
        $this->denyAccessUnlessGranted('VIEW', $etablissement);

        $alertes = $alerteRepo->findNonTraiteesForEtablissement($etablissement);

        return $this->render('app/bon_livraison/alertes.html.twig', [
            'alertes' => $alertes,
        ]);
    }

    #[Route('/en-attente', name: 'app_bl_pending', methods: ['GET'])]
    public function pending(
        BonLivraisonRepository $blRepo,
        AppLayoutExtension $layoutExtension,
    ): Response {
        $etablissement = $layoutExtension->getSelectedEtablissement();
        if (!$etablissement) {
            throw $this->createAccessDeniedException();
        }
        $this->denyAccessUnlessGranted('VIEW', $etablissement);

        $blData = $blRepo->findAnomalieByEtablissementWithAlertCount($etablissement);

        $bonsLivraison = array_map(fn (array $row) => $row['bl'], $blData);

        $selectedBl = $bonsLivraison[0] ?? null;
        $selectedAlertCount = 0;
        if ($selectedBl !== null) {
            foreach ($selectedBl->getLignes() as $ligne) {
                $selectedAlertCount += $ligne->getAlertes()->count();
            }
        }

        return $this->render('app/bon_livraison/pending.html.twig', [
            'bons_livraison' => $bonsLivraison,
            'selected_bl' => $selectedBl,
            'selected_alert_count' => $selectedAlertCount,
        ]);
    }

    #[Route('/{id}/pending-detail', name: 'app_bl_pending_detail', methods: ['GET'])]
    public function pendingDetail(BonLivraison $bonLivraison): Response
    {
        if (!$this->isGranted('VIEW', $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        $alertCount = 0;
        foreach ($bonLivraison->getLignes() as $ligne) {
            $alertCount += $ligne->getAlertes()->count();
        }

        return $this->render('app/bon_livraison/_pending_detail.html.twig', [
            'bonLivraison' => $bonLivraison,
            'alertCount' => $alertCount,
        ]);
    }

    #[Route('/{id}/detail', name: 'app_bl_show', methods: ['GET'])]
    public function show(BonLivraison $bonLivraison): Response
    {
        if (!$this->isGranted('VIEW', $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        $alertCount = 0;
        foreach ($bonLivraison->getLignes() as $ligne) {
            $alertCount += $ligne->getAlertes()->count();
        }

        return $this->render('app/bon_livraison/show.html.twig', [
            'bonLivraison' => $bonLivraison,
            'alertCount' => $alertCount,
        ]);
    }

    #[Route('/{id}/image', name: 'app_bl_image', methods: ['GET'])]
    public function image(BonLivraison $bonLivraison): Response
    {
        if (!$this->isGranted('VIEW', $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        return $this->imageService->getImageResponse($bonLivraison);
    }
}
