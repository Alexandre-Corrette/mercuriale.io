<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\FactureFournisseur;
use App\Entity\Utilisateur;
use App\Enum\StatutFacture;
use App\Exception\InvalidFileException;
use App\Form\FactureUploadType;
use App\Message\ProcessFactureOcrMessage;
use App\Repository\AvoirFournisseurRepository;
use App\Repository\FactureFournisseurRepository;
use App\Repository\TransitionFactureRepository;
use App\Service\EInvoicing\FactureWorkflowService;
use App\Service\Upload\FactureUploadService;
use App\Twig\Extension\AppLayoutExtension;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/fournisseurs/factures')]
#[IsGranted('ROLE_USER')]
#[IsGranted('VERIFIED_FEATURE')]
class FactureFournisseurController extends AbstractController
{
    public function __construct(
        private readonly FactureFournisseurRepository $factureRepo,
        private readonly FactureWorkflowService $workflowService,
        private readonly FactureUploadService $uploadService,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'app_factures_hub', methods: ['GET'])]
    public function hub(): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        return $this->render('app/facture/hub.html.twig', [
            'count_received' => $this->factureRepo->countReceivedForOrganisation($org),
            'count_to_pay' => $this->factureRepo->countByStatutForOrganisation($org, StatutFacture::ACCEPTEE),
            'count_overdue' => $this->factureRepo->countOverdueForOrganisation($org),
            'count_archived' => $this->factureRepo->countArchivedForOrganisation($org),
        ]);
    }

    #[Route('/liste', name: 'app_factures_liste', methods: ['GET'])]
    public function liste(
        AppLayoutExtension $layoutExtension,
        Request $request,
    ): Response {
        $etablissement = $layoutExtension->getSelectedEtablissement();
        if (!$etablissement) {
            throw $this->createAccessDeniedException();
        }

        $statutFilter = $request->query->get('statut');
        $statut = $statutFilter ? StatutFacture::tryFrom($statutFilter) : null;

        return $this->render('app/facture/liste.html.twig', [
            'factures' => $this->factureRepo->findForEtablissement($etablissement, $statut),
            'statut_filter' => $statutFilter,
        ]);
    }

    #[Route('/recues', name: 'app_factures_recues', methods: ['GET'])]
    public function recues(): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        $factures = $this->factureRepo->findReceivedForOrganisation($org);
        $selected = $factures[0] ?? null;

        return $this->render('app/facture/recues.html.twig', [
            'factures' => $factures,
            'selected' => $selected,
        ]);
    }

    #[Route('/recues/{id}/detail', name: 'app_facture_recue_detail', methods: ['GET'])]
    public function recueDetail(FactureFournisseur $facture): Response
    {
        $this->denyAccessUnlessGranted('FACTURE_VIEW', $facture);

        return $this->render('app/facture/_recue_detail.html.twig', [
            'facture' => $facture,
            'can_accepter' => $this->workflowService->canTransition($facture, StatutFacture::ACCEPTEE),
            'can_refuser' => $this->workflowService->canTransition($facture, StatutFacture::REFUSEE),
        ]);
    }

    #[Route('/a-payer', name: 'app_factures_a_payer', methods: ['GET'])]
    public function aPayer(): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        $factures = $this->factureRepo->findToBePaidForOrganisation($org);
        $selected = $factures[0] ?? null;
        $overdueCount = $this->factureRepo->countOverdueForOrganisation($org);

        return $this->render('app/facture/a_payer.html.twig', [
            'factures' => $factures,
            'selected' => $selected,
            'overdue_count' => $overdueCount,
        ]);
    }

    #[Route('/a-payer/{id}/detail', name: 'app_facture_a_payer_detail', methods: ['GET'])]
    public function aPayerDetail(FactureFournisseur $facture): Response
    {
        $this->denyAccessUnlessGranted('FACTURE_VIEW', $facture);

        return $this->render('app/facture/_a_payer_detail.html.twig', [
            'facture' => $facture,
            'can_payer' => $this->workflowService->canTransition($facture, StatutFacture::PAYEE),
            'can_contester' => $this->workflowService->canTransition($facture, StatutFacture::CONTESTEE),
        ]);
    }

    #[Route('/archive', name: 'app_factures_archive', methods: ['GET'])]
    public function archive(Request $request): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $org = $user->getOrganisation();

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $factures = $this->factureRepo->findArchivedForOrganisation($org, $limit, $offset);
        $total = $this->factureRepo->countArchivedForOrganisation($org);
        $selected = $factures[0] ?? null;

        return $this->render('app/facture/archive.html.twig', [
            'factures' => $factures,
            'selected' => $selected,
            'page' => $page,
            'total' => $total,
            'total_pages' => (int) ceil($total / $limit),
        ]);
    }

    #[Route('/archive/{id}/detail', name: 'app_facture_archive_detail', methods: ['GET'])]
    public function archiveDetail(
        FactureFournisseur $facture,
        TransitionFactureRepository $transitionRepo,
    ): Response {
        $this->denyAccessUnlessGranted('FACTURE_VIEW', $facture);

        return $this->render('app/facture/_archive_detail.html.twig', [
            'facture' => $facture,
            'transitions' => $transitionRepo->findByFacture($facture),
        ]);
    }

    #[Route('/upload', name: 'app_facture_upload', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_GERANT')]
    public function upload(
        AppLayoutExtension $layoutExtension,
        Request $request,
    ): Response {
        $etablissement = $layoutExtension->getSelectedEtablissement();
        if (!$etablissement) {
            throw $this->createAccessDeniedException();
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();

        $form = $this->createForm(FactureUploadType::class, null, ['user' => $user]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('fichier')->getData();
            $fournisseur = $form->get('fournisseur')->getData();

            try {
                $facture = $this->uploadService->upload($file, $etablissement, $user, $fournisseur);

                $this->messageBus->dispatch(
                    new ProcessFactureOcrMessage($facture->getIdAsString())
                );

                $this->logger->info('Facture uploadée, OCR dispatché', [
                    'facture_id' => $facture->getIdAsString(),
                    'filename' => $facture->getFichierOriginalNom(),
                ]);

                $this->addFlash('success', sprintf(
                    'Facture "%s" uploadée avec succès. L\'extraction OCR est en cours...',
                    $facture->getFichierOriginalNom()
                ));

                return $this->redirectToRoute('app_facture_show', ['id' => $facture->getIdAsString()]);
            } catch (InvalidFileException $e) {
                $this->addFlash('error', $e->getMessage());
            } catch (\Throwable $e) {
                $this->logger->error('Erreur upload facture', [
                    'error' => $e->getMessage(),
                ]);
                $this->addFlash('error', 'Une erreur inattendue est survenue lors de l\'upload.');
            }
        }

        return $this->render('app/facture/upload.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_facture_show', methods: ['GET'])]
    public function show(
        FactureFournisseur $facture,
        TransitionFactureRepository $transitionRepo,
    ): Response {
        $this->denyAccessUnlessGranted('FACTURE_VIEW', $facture);

        return $this->render('app/facture/show.html.twig', [
            'facture' => $facture,
            'transitions' => $transitionRepo->findByFacture($facture),
            'can_accepter' => $this->workflowService->canTransition($facture, StatutFacture::ACCEPTEE),
            'can_refuser' => $this->workflowService->canTransition($facture, StatutFacture::REFUSEE),
            'can_payer' => $this->workflowService->canTransition($facture, StatutFacture::PAYEE),
            'can_contester' => $this->workflowService->canTransition($facture, StatutFacture::CONTESTEE),
        ]);
    }

    #[Route('/{id}/accepter', name: 'app_facture_accepter', methods: ['POST'])]
    public function accepter(FactureFournisseur $facture, Request $request): Response
    {
        $this->denyAccessUnlessGranted('FACTURE_MANAGE', $facture);

        if (!$this->isCsrfTokenValid('facture_accepter_' . $facture->getIdAsString(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        try {
            /** @var Utilisateur $user */
            $user = $this->getUser();
            $this->workflowService->accepter($facture, $user);
            $this->addFlash('success', 'Facture acceptée avec succès.');
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_factures_recues');
    }

    #[Route('/{id}/refuser', name: 'app_facture_refuser', methods: ['POST'])]
    public function refuser(FactureFournisseur $facture, Request $request): Response
    {
        $this->denyAccessUnlessGranted('FACTURE_MANAGE', $facture);

        if (!$this->isCsrfTokenValid('facture_refuser_' . $facture->getIdAsString(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $motif = trim((string) $request->request->get('motif', ''));

        try {
            /** @var Utilisateur $user */
            $user = $this->getUser();
            $this->workflowService->refuser($facture, $motif, $user);
            $this->addFlash('success', 'Facture refusée.');
        } catch (\InvalidArgumentException|\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_factures_recues');
    }

    #[Route('/{id}/payer', name: 'app_facture_payer', methods: ['POST'])]
    public function payer(FactureFournisseur $facture, Request $request): Response
    {
        $this->denyAccessUnlessGranted('FACTURE_MANAGE', $facture);

        if (!$this->isCsrfTokenValid('facture_payer_' . $facture->getIdAsString(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $referencePaiement = trim((string) $request->request->get('reference_paiement', ''));

        try {
            /** @var Utilisateur $user */
            $user = $this->getUser();
            $this->workflowService->marquerPayee($facture, $user, $referencePaiement ?: null);
            $this->addFlash('success', 'Facture marquée comme payée.');
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_factures_a_payer');
    }

    #[Route('/{id}/contester', name: 'app_facture_contester', methods: ['POST'])]
    public function contester(FactureFournisseur $facture, Request $request): Response
    {
        $this->denyAccessUnlessGranted('FACTURE_MANAGE', $facture);

        if (!$this->isCsrfTokenValid('facture_contester_' . $facture->getIdAsString(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $motif = trim((string) $request->request->get('motif', ''));

        try {
            /** @var Utilisateur $user */
            $user = $this->getUser();
            $this->workflowService->contester($facture, $motif, $user);
            $this->addFlash('success', 'Facture contestée. Un avoir brouillon peut être créé.');
        } catch (\InvalidArgumentException|\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_factures_a_payer');
    }

    #[Route('/{id}/remettre', name: 'app_facture_remettre', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function remettre(FactureFournisseur $facture, Request $request): Response
    {
        $this->denyAccessUnlessGranted('FACTURE_MANAGE', $facture);

        if (!$this->isCsrfTokenValid('facture_remettre_' . $facture->getIdAsString(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        try {
            /** @var Utilisateur $user */
            $user = $this->getUser();
            $this->workflowService->remettre($facture, $user);
            $this->addFlash('success', 'Facture remise en réception.');
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_factures_recues');
    }

    #[Route('/{id}/document', name: 'app_facture_document', methods: ['GET'])]
    public function document(FactureFournisseur $facture): Response
    {
        $this->denyAccessUnlessGranted('FACTURE_VIEW', $facture);

        $path = $facture->getDocumentOriginalPath();
        if ($path === null) {
            throw $this->createNotFoundException('Aucun document associé.');
        }

        $fullPath = $this->uploadService->getUploadDirectory() . '/' . $path;
        if (!file_exists($fullPath)) {
            throw $this->createNotFoundException('Document introuvable.');
        }

        return new BinaryFileResponse($fullPath);
    }
}
