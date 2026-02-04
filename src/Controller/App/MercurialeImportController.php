<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\DTO\Import\ColumnMappingConfig;
use App\DTO\Import\ImportPreview;
use App\DTO\Import\ImportResult;
use App\Entity\MercurialeImport;
use App\Entity\Utilisateur;
use App\Enum\StatutImport;
use App\Exception\Import\ImportException;
use App\Form\MercurialeColumnMappingType;
use App\Form\MercurialeImportUploadType;
use App\Repository\MercurialeImportRepository;
use App\Service\Import\MercurialeBulkImporter;
use App\Service\Import\MercurialeFileParser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/mercuriale/import')]
#[IsGranted('ROLE_USER')]
class MercurialeImportController extends AbstractController
{
    public function __construct(
        private readonly MercurialeFileParser $fileParser,
        private readonly MercurialeBulkImporter $bulkImporter,
        private readonly MercurialeImportRepository $importRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactory $mercurialeImportLimiter,
    ) {}

    #[Route('', name: 'app_mercuriale_import', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $form = $this->createForm(MercurialeImportUploadType::class, null, [
            'user' => $user,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Rate limiting
            $limiter = $this->mercurialeImportLimiter->create($user->getUserIdentifier());
            if (!$limiter->consume(1)->isAccepted()) {
                $this->addFlash('error', 'Trop d\'imports récents. Veuillez patienter une heure.');

                return $this->redirectToRoute('app_mercuriale_import');
            }

            $fournisseur = $form->get('fournisseur')->getData();
            $etablissement = $form->get('etablissement')->getData();
            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile $file */
            $file = $form->get('file')->getData();

            // Verify access to fournisseur
            if (!$this->isGranted('VIEW', $fournisseur)) {
                $this->logger->warning('Tentative d\'import non autorisée', [
                    'user_id' => $user->getId(),
                    'fournisseur_id' => $fournisseur->getId(),
                ]);
                throw $this->createAccessDeniedException('Vous n\'avez pas accès à ce fournisseur.');
            }

            // Verify access to etablissement if provided
            if ($etablissement !== null && !$this->isGranted('VIEW', $etablissement)) {
                throw $this->createAccessDeniedException('Vous n\'avez pas accès à cet établissement.');
            }

            try {
                // Parse the file
                $parsedData = $this->fileParser->parse($file);

                // Auto-detect column mapping
                $detectedMapping = $this->fileParser->detectColumnMapping($parsedData['headers']);

                // Create import entity
                $import = new MercurialeImport();
                $import->setFournisseur($fournisseur);
                $import->setEtablissement($etablissement);
                $import->setCreatedBy($user);
                $import->setOriginalFilename($file->getClientOriginalName());
                $import->setParsedData($parsedData);
                $import->setTotalRows($parsedData['totalRows']);
                $import->setDetectedHeaders($parsedData['headers']);
                $import->setStatus(StatutImport::MAPPING);

                // Store initial column mapping
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

                $this->logger->info('Fichier mercuriale uploadé', [
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

        // Get pending imports for this user
        $pendingImports = $this->importRepository->findPendingByUser($user);

        return $this->render('app/mercuriale_import/index.html.twig', [
            'form' => $form,
            'pendingImports' => $pendingImports,
        ]);
    }

    #[Route('/mapping/{importId}', name: 'app_mercuriale_import_mapping', methods: ['GET', 'POST'])]
    public function mapping(string $importId, Request $request): Response
    {
        $import = $this->getValidImport($importId);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        // Verify ownership
        if ($import->getCreatedBy() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $parsedData = $import->getParsedData();
        $headers = $parsedData['headers'] ?? [];
        $currentMapping = $import->getColumnMapping();

        $form = $this->createForm(MercurialeColumnMappingType::class, null, [
            'headers' => $headers,
        ]);

        // Pre-fill form with current mapping
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
            // Build mapping from form data
            $mapping = [];
            $formData = $form->getData();

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

            // Validate required fields
            if (!$config->hasRequiredFields()) {
                $missing = $config->getMissingRequiredFields();
                $this->addFlash('error', sprintf(
                    'Colonnes obligatoires non mappées : %s',
                    implode(', ', $missing),
                ));

                return $this->render('app/mercuriale_import/mapping.html.twig', [
                    'form' => $form,
                    'import' => $import,
                    'headers' => $headers,
                    'previewRows' => \array_slice($parsedData['rows'] ?? [], 0, 5),
                ]);
            }

            // Save mapping
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

    #[Route('/preview/{importId}', name: 'app_mercuriale_import_preview', methods: ['GET', 'POST'])]
    public function preview(string $importId, Request $request): Response
    {
        $import = $this->getValidImport($importId);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        if ($import->getCreatedBy() !== $user) {
            throw $this->createAccessDeniedException();
        }

        // Run preview if not already done or if requested
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

        // Handle confirmation
        if ($request->isMethod('POST') && $preview !== null && $preview->canProceed()) {
            // Verify CSRF token
            if (!$this->isCsrfTokenValid('confirm_import_' . $importId, $request->request->getString('_token'))) {
                $this->addFlash('error', 'Token de sécurité invalide.');

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

    #[Route('/confirm/{importId}', name: 'app_mercuriale_import_confirm', methods: ['POST'])]
    public function confirm(string $importId, Request $request): Response
    {
        $import = $this->getValidImport($importId);

        /** @var Utilisateur $user */
        $user = $this->getUser();

        if ($import->getCreatedBy() !== $user) {
            throw $this->createAccessDeniedException();
        }

        // Verify CSRF token
        if (!$this->isCsrfTokenValid('confirm_import_' . $importId, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');

            return $this->redirectToRoute('app_mercuriale_import_preview', ['importId' => $importId]);
        }

        try {
            $result = $this->bulkImporter->execute($import, $user);

            $this->logger->info('Import mercuriale terminé', [
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

    #[Route('/result/{importId}', name: 'app_mercuriale_import_result', methods: ['GET'])]
    public function result(string $importId): Response
    {
        $import = $this->importRepository->findByUuid($importId);

        if ($import === null) {
            throw $this->createNotFoundException('Import non trouvé.');
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

    #[Route('/cancel/{importId}', name: 'app_mercuriale_import_cancel', methods: ['POST'])]
    public function cancel(string $importId, Request $request): Response
    {
        $import = $this->importRepository->findByUuid($importId);

        if ($import === null) {
            throw $this->createNotFoundException('Import non trouvé.');
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();

        if ($import->getCreatedBy() !== $user) {
            throw $this->createAccessDeniedException();
        }

        // Verify CSRF token
        if (!$this->isCsrfTokenValid('cancel_import_' . $importId, $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide.');

            return $this->redirectToRoute('app_mercuriale_import');
        }

        // Only allow cancellation of pending imports
        if (\in_array($import->getStatus(), [StatutImport::PENDING, StatutImport::MAPPING, StatutImport::PREVIEWED], true)) {
            $this->entityManager->remove($import);
            $this->entityManager->flush();
            $this->addFlash('success', 'Import annulé.');
        }

        return $this->redirectToRoute('app_mercuriale_import');
    }

    private function getValidImport(string $importId): MercurialeImport
    {
        $import = $this->importRepository->findByUuid($importId);

        if ($import === null) {
            throw $this->createNotFoundException('Import non trouvé.');
        }

        if ($import->isExpired()) {
            $import->setStatus(StatutImport::EXPIRED);
            $this->entityManager->flush();
            throw $this->createNotFoundException('Cet import a expiré. Veuillez recommencer.');
        }

        return $import;
    }
}
