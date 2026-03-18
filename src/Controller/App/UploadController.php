<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\DTO\Import\ColumnMappingConfig;
use App\DTO\Import\ImportResult;
use App\DTO\UploadResult;
use App\Entity\BonLivraison;
use App\Entity\Etablissement;
use App\Entity\MercurialeImport;
use App\Entity\Utilisateur;
use App\Enum\StatutBonLivraison;
use App\Enum\StatutImport;
use App\Exception\Import\ImportException;
use App\Form\BonLivraisonUploadType;
use App\Form\MercurialeColumnMappingType;
use App\Form\MercurialeImportUploadType;
use App\Repository\BonLivraisonRepository;
use App\Repository\FournisseurRepository;
use App\Repository\MercurialeImportRepository;
use App\Service\Controle\ControleService;
use App\Service\Import\MercurialeBulkImporter;
use App\Service\Import\MercurialeFileParser;
use App\Service\Ocr\BonLivraisonExtractorService;
use App\Service\Upload\BonLivraisonUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class UploadController extends AbstractController
{
    public function __construct(
        private readonly BonLivraisonUploadService $uploadService,
        private readonly BonLivraisonExtractorService $extractorService,
        private readonly ControleService $controleService,
        private readonly MercurialeFileParser $fileParser,
        private readonly MercurialeBulkImporter $bulkImporter,
        private readonly MercurialeImportRepository $importRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactory $blUploadLimiter,
        private readonly RateLimiterFactory $mercurialeImportLimiter,
    ) {
    }

    // ─── BL Upload ────────────────────────────────────────────────────

    #[Route('/app/bl/upload', name: 'app_bl_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $form = $this->createForm(BonLivraisonUploadType::class, null, [
            'user' => $user,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $etablissement = $form->get('etablissement')->getData();
            /** @var array<int, \Symfony\Component\HttpFoundation\File\UploadedFile> $files */
            $files = $form->get('files')->getData();

            // Voter check
            if (!$this->isGranted('UPLOAD', $etablissement)) {
                $this->logger->warning('Tentative d\'upload non autorisee', [
                    'user_id' => $user->getId(),
                    'etablissement_id' => $etablissement->getId(),
                ]);
                throw $this->createAccessDeniedException('Vous n\'avez pas acces a cet etablissement.');
            }

            // Rate limiting
            $limiter = $this->blUploadLimiter->create($user->getUserIdentifier());
            if (!$limiter->consume(count($files))->isAccepted()) {
                $this->addFlash('error', 'Trop de tentatives d\'upload. Veuillez patienter une minute.');
                return $this->redirectToRoute('app_bl_upload');
            }

            $result = $this->uploadService->uploadMultiple($files, $etablissement, $user);

            $this->logger->info('Upload multiple termine', [
                'user_id' => $user->getId(),
                'etablissement_id' => $etablissement->getId(),
                'success_count' => $result->getSuccessCount(),
                'failure_count' => $result->getFailureCount(),
            ]);

            $this->handleUploadResultFlashes($result);

            return $this->handleUploadResultRedirect($result);
        }

        return $this->render('app/bon_livraison/upload.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/app/bl/{id}/validate', name: 'app_bl_validate', methods: ['GET'])]
    public function validate(BonLivraison $bonLivraison): Response
    {
        if (!$this->isGranted('VIEW', $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        return $this->redirectToRoute('app_bl_extraction', ['id' => $bonLivraison->getId()]);
    }

    #[Route('/app/bl/{id}/extraction', name: 'app_bl_extraction', methods: ['GET'])]
    public function extraction(BonLivraison $bonLivraison, FournisseurRepository $fournisseurRepository): Response
    {
        if (!$this->isGranted('VIEW', $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        $needsExtraction = $bonLivraison->getDonneesBrutes() === null;

        $fournisseurs = [];
        $organisation = $bonLivraison->getEtablissement()?->getOrganisation();
        if ($organisation !== null) {
            $fournisseurs = $fournisseurRepository->findByOrganisation($organisation);
        }

        return $this->render('app/bon_livraison/extraction.html.twig', [
            'bonLivraison' => $bonLivraison,
            'needsExtraction' => $needsExtraction,
            'extractionResult' => null,
            'fournisseurs' => $fournisseurs,
        ]);
    }

    #[Route('/app/bl/{id}/extraire', name: 'app_bl_extraire', methods: ['POST'])]
    public function extraire(BonLivraison $bonLivraison): JsonResponse
    {
        if (!$this->isGranted('VIEW', $bonLivraison->getEtablissement())) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();
        $limiter = $this->blUploadLimiter->create('extraction_' . $user->getUserIdentifier());
        if (!$limiter->consume(1)->isAccepted()) {
            return new JsonResponse([
                'error' => 'Trop de demandes d\'extraction. Veuillez patienter.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $result = $this->extractorService->extract($bonLivraison);

            if (!$result->success) {
                return new JsonResponse([
                    'success' => false,
                    'errors' => $result->warnings,
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return new JsonResponse([
                'success' => true,
                'confiance' => $result->confiance,
                'nombreLignes' => $result->getNombreLignes(),
                'produitsNonMatches' => $result->produitsNonMatches,
                'warnings' => $result->warnings,
                'tempsExtraction' => $result->tempsExtraction,
                'redirectUrl' => $this->generateUrl('app_bl_extraction', ['id' => $bonLivraison->getId()]),
            ]);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Impossible de compresser')) {
                return new JsonResponse([
                    'success' => false,
                    'errors' => ['L\'image est trop volumineuse. Veuillez prendre une photo en qualite standard (pas HDR/ProRAW).'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Erreur extraction BL', [
                'bl_id' => $bonLivraison->getId(),
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'errors' => ['Erreur lors de l\'extraction: ' . $e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/app/bl/{id}/valider', name: 'app_bl_valider', methods: ['POST'])]
    public function valider(BonLivraison $bonLivraison, Request $request): Response
    {
        if (!$this->isGranted('MANAGE', $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('valider_bl_' . $bonLivraison->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token de securite invalide.');
            return $this->redirectToRoute('app_bl_extraction', ['id' => $bonLivraison->getId()]);
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();

        try {
            $nombreAlertes = $this->controleService->controlerBonLivraison($bonLivraison);

            if ($nombreAlertes === 0) {
                $bonLivraison->setStatut(StatutBonLivraison::VALIDE);
                $this->addFlash('success', 'Bon de livraison valide avec succes.');
            } else {
                $bonLivraison->setStatut(StatutBonLivraison::ANOMALIE);
                $this->addFlash('warning', sprintf(
                    'Bon de livraison controle avec %d alerte(s) detectee(s).',
                    $nombreAlertes
                ));
            }

            $bonLivraison->setValidatedAt(new \DateTimeImmutable());
            $bonLivraison->setValidatedBy($user);

            $this->entityManager->flush();

            return $this->redirectToRoute('app_bl_extraction', ['id' => $bonLivraison->getId()]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur validation BL', [
                'bl_id' => $bonLivraison->getId(),
                'error' => $e->getMessage(),
            ]);

            $this->addFlash('error', 'Erreur lors de la validation: ' . $e->getMessage());
            return $this->redirectToRoute('app_bl_extraction', ['id' => $bonLivraison->getId()]);
        }
    }

    #[Route('/app/bl/{id}/rejeter', name: 'app_bl_rejeter', methods: ['POST'])]
    public function rejeter(BonLivraison $bonLivraison, Request $request): Response
    {
        if (!$this->isGranted('MANAGE', $bonLivraison->getEtablissement())) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('rejeter_bl_' . $bonLivraison->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token de securite invalide.');
            return $this->redirectToRoute('app_bl_extraction', ['id' => $bonLivraison->getId()]);
        }

        try {
            $imagePath = $bonLivraison->getImagePath();
            if ($imagePath) {
                $fullPath = $this->uploadService->getUploadDirectory() . '/' . $imagePath;
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
            }

            $this->entityManager->remove($bonLivraison);
            $this->entityManager->flush();

            $this->addFlash('success', 'Bon de livraison supprime.');
            return $this->redirectToRoute('app_bl_upload');
        } catch (\Exception $e) {
            $this->logger->error('Erreur suppression BL', [
                'bl_id' => $bonLivraison->getId(),
                'error' => $e->getMessage(),
            ]);

            $this->addFlash('error', 'Erreur lors de la suppression.');
            return $this->redirectToRoute('app_bl_extraction', ['id' => $bonLivraison->getId()]);
        }
    }

    #[Route('/app/bl/{id}/ligne/{ligneId}/corriger', name: 'app_bl_ligne_corriger', methods: ['POST'])]
    public function corrigerLigne(
        BonLivraison $bonLivraison,
        int $ligneId,
        Request $request,
    ): JsonResponse {
        if (!$this->isGranted('MANAGE', $bonLivraison->getEtablissement())) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $ligne = null;
        foreach ($bonLivraison->getLignes() as $l) {
            if ($l->getId() === $ligneId) {
                $ligne = $l;
                break;
            }
        }

        if ($ligne === null) {
            return new JsonResponse(['error' => 'Ligne non trouvee'], Response::HTTP_NOT_FOUND);
        }

        try {
            $data = json_decode($request->getContent(), true);

            if (isset($data['quantite_livree'])) {
                $ligne->setQuantiteLivree((string) $data['quantite_livree']);
            }
            if (isset($data['prix_unitaire'])) {
                $ligne->setPrixUnitaire((string) $data['prix_unitaire']);
            }
            if (isset($data['total_ligne'])) {
                $ligne->setTotalLigne((string) $data['total_ligne']);
            }

            if (!isset($data['total_ligne'])) {
                $ligne->setTotalLigne($ligne->calculerTotalLigne());
            }

            $this->entityManager->flush();

            $nombreAlertes = $this->controleService->controlerBonLivraison($bonLivraison);

            return new JsonResponse([
                'success' => true,
                'nombreAlertes' => $nombreAlertes,
                'ligne' => [
                    'id' => $ligne->getId(),
                    'quantiteLivree' => $ligne->getQuantiteLivree(),
                    'prixUnitaire' => $ligne->getPrixUnitaire(),
                    'totalLigne' => $ligne->getTotalLigne(),
                    'statutControle' => $ligne->getStatutControle()->value,
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur correction ligne BL', [
                'bl_id' => $bonLivraison->getId(),
                'ligne_id' => $ligneId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Erreur lors de la correction',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/app/bl/{id}/set-fournisseur', name: 'app_bl_set_fournisseur', methods: ['POST'])]
    public function setFournisseur(
        BonLivraison $bonLivraison,
        Request $request,
        FournisseurRepository $fournisseurRepository,
    ): JsonResponse {
        if (!$this->isGranted('MANAGE', $bonLivraison->getEtablissement())) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $fournisseurId = $data['fournisseur_id'] ?? null;

        if ($fournisseurId === null) {
            $bonLivraison->setFournisseur(null);
            $this->entityManager->flush();

            return new JsonResponse(['success' => true, 'fournisseur' => null]);
        }

        $fournisseur = $fournisseurRepository->find((int) $fournisseurId);
        if ($fournisseur === null) {
            return new JsonResponse(['error' => 'Fournisseur non trouve'], Response::HTTP_NOT_FOUND);
        }

        // Verify fournisseur belongs to same organisation as the BL (IDOR protection)
        if (!$this->isGranted('ASSIGN_TO_BL', [$fournisseur, $bonLivraison])) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $bonLivraison->setFournisseur($fournisseur);
        $this->entityManager->flush();

        $this->logger->info('Fournisseur BL corrige manuellement', [
            'bl_id' => $bonLivraison->getId(),
            'fournisseur_id' => $fournisseur->getId(),
            'fournisseur_nom' => $fournisseur->getNom(),
        ]);

        return new JsonResponse([
            'success' => true,
            'fournisseur' => [
                'id' => $fournisseur->getId(),
                'nom' => $fournisseur->getNom(),
            ],
        ]);
    }

    #[Route('/app/bl/batch-validate', name: 'app_bl_batch_validate', methods: ['GET'])]
    public function batchValidate(Request $request, BonLivraisonRepository $repository): Response
    {
        $idsParam = $request->query->getString('ids', '');

        if (empty($idsParam)) {
            $this->addFlash('error', 'Aucun bon de livraison a valider.');
            return $this->redirectToRoute('app_bl_upload');
        }

        $ids = array_filter(
            array_map('intval', explode(',', $idsParam)),
            fn (int $id) => $id > 0
        );

        if (empty($ids)) {
            $this->addFlash('error', 'Identifiants invalides.');
            return $this->redirectToRoute('app_bl_upload');
        }

        $bonsLivraison = $repository->findBy(['id' => $ids]);

        $accessibleBls = array_filter(
            $bonsLivraison,
            fn (BonLivraison $bl) => $this->isGranted('VIEW', $bl->getEtablissement())
        );

        if (empty($accessibleBls)) {
            throw $this->createAccessDeniedException('Vous n\'avez pas acces a ces bons de livraison.');
        }

        return $this->render('app/bon_livraison/batch_validate.html.twig', [
            'bonsLivraison' => $accessibleBls,
        ]);
    }

    // ─── BL Upload helpers ────────────────────────────────────────────

    private function handleUploadResultFlashes(UploadResult $result): void
    {
        if ($result->isFullSuccess()) {
            $count = $result->getSuccessCount();
            $message = $count === 1
                ? 'Bon de livraison uploade avec succes.'
                : sprintf('%d bons de livraison uploades avec succes.', $count);
            $this->addFlash('success', $message);
        } elseif ($result->isFullFailure()) {
            $this->addFlash('error', 'Aucun fichier n\'a pu etre uploade.');
            foreach ($result->getFailedUploads() as $failure) {
                $this->addFlash('error', sprintf('%s : %s', $failure['filename'], $failure['error']));
            }
        } else {
            $this->addFlash('warning', sprintf(
                '%d fichier(s) uploade(s) sur %d.',
                $result->getSuccessCount(),
                $result->getTotalCount()
            ));
            foreach ($result->getFailedUploads() as $failure) {
                $this->addFlash('error', sprintf('%s : %s', $failure['filename'], $failure['error']));
            }
        }
    }

    private function handleUploadResultRedirect(UploadResult $result): Response
    {
        if ($result->isFullFailure()) {
            return $this->redirectToRoute('app_bl_upload');
        }

        $successfulUploads = $result->getSuccessfulUploads();

        if (count($successfulUploads) === 1) {
            return $this->redirectToRoute('app_bl_validate', [
                'id' => $successfulUploads[0]->getId(),
            ]);
        }

        $ids = $result->getSuccessfulIds();
        return $this->redirectToRoute('app_bl_batch_validate', [
            'ids' => implode(',', array_filter($ids)),
        ]);
    }

    // ─── Mercuriale Import ────────────────────────────────────────────

    #[Route('/app/mercuriale/import', name: 'app_mercuriale_import', methods: ['GET', 'POST'])]
    public function mercurialeImport(Request $request): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $form = $this->createForm(MercurialeImportUploadType::class, null, [
            'user' => $user,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limiter = $this->mercurialeImportLimiter->create($user->getUserIdentifier());
            if (!$limiter->consume(1)->isAccepted()) {
                $this->addFlash('error', 'Trop d\'imports recents. Veuillez patienter une heure.');

                return $this->redirectToRoute('app_mercuriale_import');
            }

            $fournisseur = $form->get('fournisseur')->getData();
            /** @var Etablissement[] $etablissements */
            $etablissements = $form->get('etablissements')->getData();
            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile $file */
            $file = $form->get('file')->getData();

            if (!$this->isGranted('VIEW', $fournisseur)) {
                $this->logger->warning('Tentative d\'import non autorisee', [
                    'user_id' => $user->getId(),
                    'fournisseur_id' => $fournisseur->getId(),
                ]);
                throw $this->createAccessDeniedException('Vous n\'avez pas acces a ce fournisseur.');
            }

            foreach ($etablissements as $etablissement) {
                if (!$this->isGranted('VIEW', $etablissement)) {
                    throw $this->createAccessDeniedException('Vous n\'avez pas acces a cet etablissement.');
                }
            }

            try {
                $parsedData = $this->fileParser->parse($file);
                $detectedMapping = $this->fileParser->detectColumnMapping($parsedData['headers']);

                $import = new MercurialeImport();
                $import->setFournisseur($fournisseur);
                foreach ($etablissements as $etablissement) {
                    $import->addEtablissement($etablissement);
                }
                $import->setCreatedBy($user);
                $import->setOriginalFilename($file->getClientOriginalName());
                $import->setParsedData($parsedData);
                $import->setTotalRows($parsedData['totalRows']);
                $import->setDetectedHeaders($parsedData['headers']);
                $import->setStatus(StatutImport::MAPPING);

                $initialMapping = [];
                foreach ($detectedMapping as $field => $columnIndex) {
                    $initialMapping[$columnIndex] = $field;
                }
                $import->setColumnMapping([
                    'mapping' => $initialMapping,
                    'hasHeaderRow' => true,
                    'defaultUnite' => null,
                    'defaultDateDebut' => (new \DateTimeImmutable())->format('Y-m-d'),
                ]);

                $this->entityManager->persist($import);
                $this->entityManager->flush();

                $this->logger->info('Fichier mercuriale uploade', [
                    'importId' => $import->getIdAsString(),
                    'filename' => $file->getClientOriginalName(),
                    'totalRows' => $parsedData['totalRows'],
                    'detectedColumns' => array_keys($detectedMapping),
                ]);

                return $this->redirectToRoute('app_mercuriale_import_mapping', [
                    'importId' => $import->getIdAsString(),
                ]);
            } catch (ImportException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('app_mercuriale_import');
            } catch (\Exception $e) {
                $this->logger->error('Erreur upload mercuriale', [
                    'error' => $e->getMessage(),
                ]);
                $this->addFlash('error', 'Une erreur est survenue lors du traitement du fichier.');

                return $this->redirectToRoute('app_mercuriale_import');
            }
        }

        $pendingImports = $this->importRepository->findPendingByUser($user);

        return $this->render('app/mercuriale_import/index.html.twig', [
            'form' => $form,
            'pendingImports' => $pendingImports,
        ]);
    }

    #[Route('/app/mercuriale/import/mapping/{importId}', name: 'app_mercuriale_import_mapping', methods: ['GET', 'POST'])]
    public function mercurialeMapping(string $importId, Request $request): Response
    {
        $import = $this->getValidImport($importId);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        if ($import->getCreatedBy() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $parsedData = $import->getParsedData();
        $headers = $parsedData['headers'] ?? [];
        $currentMapping = $import->getColumnMapping();

        $form = $this->createForm(MercurialeColumnMappingType::class, null, [
            'headers' => $headers,
        ]);

        if ($currentMapping !== null) {
            $config = ColumnMappingConfig::fromArray($currentMapping);
            foreach ($config->mapping as $columnIndex => $field) {
                $fieldName = 'mapping_' . $field;
                if ($form->has($fieldName)) {
                    $form->get($fieldName)->setData((string) $columnIndex);
                }
            }
            if ($form->has('hasHeaderRow')) {
                $form->get('hasHeaderRow')->setData($config->hasHeaderRow);
            }
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $mapping = [];

            $fields = [
                'code_fournisseur',
                'designation',
                'prix',
                'unite',
                'conditionnement',
                'date_debut',
                'date_fin',
            ];

            foreach ($fields as $field) {
                $columnIndex = $form->get('mapping_' . $field)->getData();
                if ($columnIndex !== null && $columnIndex !== ColumnMappingConfig::FIELD_IGNORE) {
                    $mapping[(int) $columnIndex] = $field;
                }
            }

            $config = new ColumnMappingConfig(
                mapping: $mapping,
                hasHeaderRow: $form->get('hasHeaderRow')->getData() ?? true,
                defaultUnite: $form->get('defaultUnite')->getData()?->getCode(),
                defaultDateDebut: $form->get('defaultDateDebut')->getData()
                    ? \DateTimeImmutable::createFromMutable($form->get('defaultDateDebut')->getData())
                    : null,
            );

            if (!$config->hasRequiredFields()) {
                $missing = $config->getMissingRequiredFields();
                $this->addFlash('error', sprintf(
                    'Colonnes obligatoires non mappees : %s',
                    implode(', ', $missing),
                ));

                return $this->render('app/mercuriale_import/mapping.html.twig', [
                    'form' => $form,
                    'import' => $import,
                    'headers' => $headers,
                    'previewRows' => \array_slice($parsedData['rows'] ?? [], 0, 5),
                ]);
            }

            $import->setColumnMapping($config->toArray());
            $import->extendExpiration();
            $this->entityManager->flush();

            return $this->redirectToRoute('app_mercuriale_import_preview', [
                'importId' => $import->getIdAsString(),
            ]);
        }

        return $this->render('app/mercuriale_import/mapping.html.twig', [
            'form' => $form,
            'import' => $import,
            'headers' => $headers,
            'previewRows' => \array_slice($parsedData['rows'] ?? [], 0, 5),
        ]);
    }

    #[Route('/app/mercuriale/import/preview/{importId}', name: 'app_mercuriale_import_preview', methods: ['GET', 'POST'])]
    public function mercurialePreview(string $importId, Request $request): Response
    {
        $import = $this->getValidImport($importId);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        if ($import->getCreatedBy() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $preview = null;
        $error = null;

        try {
            $preview = $this->bulkImporter->preview($import);
        } catch (ImportException $e) {
            $error = $e->getMessage();
        } catch (\Exception $e) {
            $this->logger->error('Erreur preview import', [
                'importId' => $importId,
                'error' => $e->getMessage(),
            ]);
            $error = 'Une erreur est survenue lors de l\'analyse du fichier.';
        }

        if ($request->isMethod('POST') && $preview !== null && $preview->canProceed()) {
            if (!$this->isCsrfTokenValid('confirm_import_' . $importId, $request->request->getString('_token'))) {
                $this->addFlash('error', 'Token de securite invalide.');

                return $this->redirectToRoute('app_mercuriale_import_preview', ['importId' => $importId]);
            }

            return $this->redirectToRoute('app_mercuriale_import_confirm', ['importId' => $importId]);
        }

        return $this->render('app/mercuriale_import/preview.html.twig', [
            'import' => $import,
            'preview' => $preview,
            'error' => $error,
        ]);
    }

    #[Route('/app/mercuriale/import/confirm/{importId}', name: 'app_mercuriale_import_confirm', methods: ['POST'])]
    public function mercurialeConfirm(string $importId, Request $request): Response
    {
        $import = $this->getValidImport($importId);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        if ($import->getCreatedBy() !== $user) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('confirm_import_' . $importId, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token de securite invalide.');

            return $this->redirectToRoute('app_mercuriale_import_preview', ['importId' => $importId]);
        }

        try {
            $result = $this->bulkImporter->execute($import, $user);

            $this->logger->info('Import mercuriale termine', [
                'importId' => $importId,
                'result' => $result->toArray(),
            ]);

            return $this->redirectToRoute('app_mercuriale_import_result', ['importId' => $importId]);
        } catch (ImportException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_mercuriale_import_preview', ['importId' => $importId]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur import mercuriale', [
                'importId' => $importId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'previous' => $e->getPrevious() ? $e->getPrevious()->getMessage() : null,
            ]);
            $this->addFlash('error', 'Une erreur est survenue lors de l\'import.');

            return $this->redirectToRoute('app_mercuriale_import_preview', ['importId' => $importId]);
        }
    }

    #[Route('/app/mercuriale/import/result/{importId}', name: 'app_mercuriale_import_result', methods: ['GET'])]
    public function mercurialeResult(string $importId): Response
    {
        $import = $this->importRepository->findByUuid($importId);

        if ($import === null) {
            throw $this->createNotFoundException('Import non trouve.');
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();

        if ($import->getCreatedBy() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $resultData = $import->getImportResult();
        $result = $resultData !== null ? ImportResult::fromArray($resultData) : null;

        return $this->render('app/mercuriale_import/result.html.twig', [
            'import' => $import,
            'result' => $result,
        ]);
    }

    #[Route('/app/mercuriale/import/cancel/{importId}', name: 'app_mercuriale_import_cancel', methods: ['POST'])]
    public function mercurialeCancel(string $importId, Request $request): Response
    {
        $import = $this->importRepository->findByUuid($importId);

        if ($import === null) {
            throw $this->createNotFoundException('Import non trouve.');
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();

        if ($import->getCreatedBy() !== $user) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('cancel_import_' . $importId, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token de securite invalide.');

            return $this->redirectToRoute('app_mercuriale_import');
        }

        if (\in_array($import->getStatus(), [StatutImport::PENDING, StatutImport::MAPPING, StatutImport::PREVIEWED], true)) {
            $this->entityManager->remove($import);
            $this->entityManager->flush();
            $this->addFlash('success', 'Import annule.');
        }

        return $this->redirectToRoute('app_mercuriale_import');
    }

    // ─── Mercuriale Import helpers ────────────────────────────────────

    private function getValidImport(string $importId): MercurialeImport
    {
        $import = $this->importRepository->findByUuid($importId);

        if ($import === null) {
            throw $this->createNotFoundException('Import non trouve.');
        }

        if ($import->isExpired()) {
            $import->setStatus(StatutImport::EXPIRED);
            $this->entityManager->flush();
            throw $this->createNotFoundException('Cet import a expire. Veuillez recommencer.');
        }

        return $import;
    }
}
