<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\DTO\Import\ColumnMappingConfig;
use App\DTO\Import\ImportError;
use App\DTO\Import\ImportPreview;
use App\DTO\Import\ImportPreviewLine;
use App\DTO\Import\ImportResult;
use App\DTO\Import\ImportWarning;
use App\Entity\Etablissement;
use App\Entity\Fournisseur;
use App\Entity\Mercuriale;
use App\Entity\MercurialeImport;
use App\Entity\ProduitFournisseur;
use App\Entity\Utilisateur;
use App\Enum\StatutImport;
use App\Exception\Import\ImportException;
use App\Repository\MercurialeRepository;
use App\Repository\ProduitFournisseurRepository;
use App\Repository\UniteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

class MercurialeBulkImporter
{
    private const BATCH_SIZE = 100;
    private const TIMEOUT_SECONDS = 60;

    /**
     * Generate a code from designation (used when code is missing).
     */
    private function generateCodeFromDesignation(string $designation): string
    {
        // Normalize: uppercase, remove accents, keep only alphanumeric
        $code = mb_strtoupper($designation);
        $code = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $code) ?: $code;
        $code = preg_replace('/[^A-Z0-9]/', '', $code) ?? '';

        // Truncate to max 20 chars and add hash suffix for uniqueness
        $code = substr($code, 0, 15);
        $hash = substr(md5($designation), 0, 4);

        return $code . '_' . strtoupper($hash);
    }

    public function __construct(
        private EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly ProduitFournisseurRepository $produitFournisseurRepository,
        private readonly MercurialeRepository $mercurialeRepository,
        private readonly UniteRepository $uniteRepository,
        private readonly ColumnMapper $columnMapper,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Preview the import without persisting changes.
     */
    public function preview(MercurialeImport $import): ImportPreview
    {
        if (!$import->canBeProcessed()) {
            throw new ImportException(ImportException::ERROR_IMPORT_EXPIRED);
        }

        $mapping = $import->getColumnMapping();
        if ($mapping === null) {
            throw new ImportException(ImportException::ERROR_INVALID_MAPPING);
        }

        $config = ColumnMappingConfig::fromArray($mapping);

        if (!$config->hasRequiredFields()) {
            throw ImportException::missingColumns($config->getMissingRequiredFields());
        }

        $parsedData = $import->getParsedData();
        $rows = $parsedData['rows'] ?? [];

        $lines = [];
        $globalErrors = [];
        $globalWarnings = [];

        $createCount = 0;
        $updateCount = 0;
        $skipCount = 0;
        $errorCount = 0;

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // +2 for header row and 1-based index
            $line = $this->previewRow($row, $rowNumber, $config, $import->getFournisseur(), $import->getEtablissement());

            $lines[] = $line;

            match ($line->action) {
                ImportPreviewLine::ACTION_CREATE => ++$createCount,
                ImportPreviewLine::ACTION_UPDATE => ++$updateCount,
                ImportPreviewLine::ACTION_SKIP => ++$skipCount,
                ImportPreviewLine::ACTION_ERROR => ++$errorCount,
                default => null, // Handle any unexpected action
            };
        }

        $preview = new ImportPreview(
            totalRows: \count($rows),
            validRows: $createCount + $updateCount,
            errorRows: $errorCount,
            createCount: $createCount,
            updateCount: $updateCount,
            skipCount: $skipCount,
            lines: $lines,
            globalErrors: $globalErrors,
            globalWarnings: $globalWarnings,
        );

        // Update import with preview result
        $import->setPreviewResult($preview->toArray());
        $import->setStatus(StatutImport::PREVIEWED);
        $import->extendExpiration();

        $this->entityManager->flush();

        return $preview;
    }

    private function previewRow(
        array $row,
        int $rowNumber,
        ColumnMappingConfig $config,
        Fournisseur $fournisseur,
        ?Etablissement $etablissement,
    ): ImportPreviewLine {
        $errors = [];
        $warnings = [];

        // Map row data
        $mappedData = $this->columnMapper->mapRow($row, $config);

        // Validate mapped data
        $validation = $this->columnMapper->validateMappedRow($mappedData);

        // Collect errors (blocking)
        if (!$validation['valid']) {
            foreach ($validation['errors'] as $error) {
                $errors[] = new ImportError(
                    row: $rowNumber,
                    column: $error['field'],
                    message: $error['message'],
                    value: $mappedData[$error['field']] ?? null,
                );
            }

            return new ImportPreviewLine(
                row: $rowNumber,
                action: ImportPreviewLine::ACTION_ERROR,
                codeFournisseur: $mappedData['code_fournisseur'],
                designation: $mappedData['designation'],
                unite: $mappedData['unite'],
                prix: $mappedData['prix'],
                errors: $errors,
                rawData: $row,
            );
        }

        // Collect warnings (non-blocking)
        foreach ($validation['warnings'] ?? [] as $warning) {
            $warnings[] = new ImportWarning(
                row: $rowNumber,
                column: $warning['field'],
                message: $warning['message'],
                value: $mappedData[$warning['field']] ?? null,
            );
        }

        // Generate code from designation if missing
        if (empty($mappedData['code_fournisseur']) && !empty($mappedData['designation'])) {
            $mappedData['code_fournisseur'] = $this->generateCodeFromDesignation($mappedData['designation']);
        }

        // Use generated code for preview display
        $displayCode = $mappedData['code_fournisseur'];

        // Check if product already exists
        $existingProduct = $this->produitFournisseurRepository->findOneBy([
            'fournisseur' => $fournisseur,
            'codeFournisseur' => $displayCode,
        ]);

        $action = ImportPreviewLine::ACTION_CREATE;
        $existingProductId = null;
        $existingMercurialeId = null;

        if ($existingProduct !== null) {
            $existingProductId = $existingProduct->getId();
            $action = ImportPreviewLine::ACTION_UPDATE;

            // Check if there's an existing mercuriale
            $dateDebut = $mappedData['date_debut']
                ? new \DateTimeImmutable($mappedData['date_debut'])
                : new \DateTimeImmutable();

            $existingMercuriale = $this->mercurialeRepository->findPrixValide(
                $existingProduct,
                $etablissement,
                $dateDebut,
            );

            if ($existingMercuriale !== null) {
                $existingMercurialeId = $existingMercuriale->getId();

                // Check if price is different (only if new price is valid)
                $existingPrice = $existingMercuriale->getPrixNegocie();
                $newPrice = $mappedData['prix'];
                $newPriceIsValid = !empty($newPrice) && is_numeric($newPrice) && (float) $newPrice > 0;

                if ($newPriceIsValid && bccomp($existingPrice, $newPrice, 4) === 0) {
                    $action = ImportPreviewLine::ACTION_SKIP;
                    $warnings[] = new ImportWarning(
                        row: $rowNumber,
                        column: 'prix',
                        message: 'Le prix est identique au prix actuel',
                        value: $newPrice,
                    );
                }
            }
        }

        // Check unit
        if (!empty($mappedData['unite'])) {
            $unit = $this->columnMapper->resolveUnite($mappedData['unite']);
            if ($unit === null) {
                $warnings[] = new ImportWarning(
                    row: $rowNumber,
                    column: 'unite',
                    message: sprintf('Unité "%s" non trouvée, sera créée avec l\'unité par défaut', $mappedData['unite']),
                    value: $mappedData['unite'],
                );
            }
        }

        // Check if price is valid - if not, product will be created without mercuriale
        $hasValidPrice = !empty($mappedData['prix'])
            && is_numeric($mappedData['prix'])
            && (float) $mappedData['prix'] > 0;

        if (!$hasValidPrice && $action !== ImportPreviewLine::ACTION_SKIP) {
            // No valid price but we can still create/update the product
            $warnings[] = new ImportWarning(
                row: $rowNumber,
                column: 'prix',
                message: 'Prix absent ou invalide - produit créé sans prix négocié',
                value: $mappedData['prix'],
            );
        }

        return new ImportPreviewLine(
            row: $rowNumber,
            action: $action,
            codeFournisseur: $displayCode,
            designation: $mappedData['designation'],
            unite: $mappedData['unite'],
            prix: $mappedData['prix'],
            existingProductId: $existingProductId,
            existingMercurialeId: $existingMercurialeId,
            warnings: $warnings,
            rawData: $row,
        );
    }

    /**
     * Execute the import with database transaction.
     */
    public function execute(MercurialeImport $import, Utilisateur $user): ImportResult
    {
        if (!$import->canBeProcessed()) {
            throw new ImportException(ImportException::ERROR_IMPORT_EXPIRED);
        }

        if ($import->getStatus() !== StatutImport::PREVIEWED) {
            throw new ImportException(
                ImportException::ERROR_INVALID_MAPPING,
                'L\'import doit être prévisualisé avant exécution',
            );
        }

        $mapping = $import->getColumnMapping();
        if ($mapping === null) {
            throw new ImportException(ImportException::ERROR_INVALID_MAPPING);
        }

        $config = ColumnMappingConfig::fromArray($mapping);
        $parsedData = $import->getParsedData();
        $rows = $parsedData['rows'] ?? [];

        $startTime = microtime(true);
        $deadline = time() + self::TIMEOUT_SECONDS;

        $productsCreated = 0;
        $productsUpdated = 0;
        $mercurialesCreated = 0;
        $mercurialesUpdated = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        $this->entityManager->beginTransaction();

        try {
            // Get default unit (code 'p' for Pièce)
            $defaultUnit = $this->uniteRepository->findOneBy(['code' => 'p']);

            $batchCount = 0;

            foreach ($rows as $index => $row) {
                if (time() > $deadline) {
                    throw new ImportException(
                        ImportException::ERROR_IMPORT_FAILED,
                        'Timeout: l\'import a pris trop de temps',
                    );
                }

                $rowNumber = $index + 2;

                try {
                    $result = $this->processRow(
                        $row,
                        $rowNumber,
                        $config,
                        $import->getFournisseur(),
                        $import->getEtablissement(),
                        $user,
                        $defaultUnit,
                    );

                    match ($result['action']) {
                        'product_created' => ++$productsCreated,
                        'product_updated' => ++$productsUpdated,
                        default => null,
                    };

                    match ($result['mercuriale_action'] ?? null) {
                        'created' => ++$mercurialesCreated,
                        'updated' => ++$mercurialesUpdated,
                        default => null,
                    };

                    if ($result['action'] === 'skipped') {
                        ++$skipped;
                    }
                } catch (\Exception $e) {
                    ++$failed;
                    $errors[] = new ImportError(
                        row: $rowNumber,
                        column: 'general',
                        message: $e->getMessage(),
                    );

                    $this->logger->error('Failed to import row', [
                        'row' => $rowNumber,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'rowData' => $row,
                    ]);

                    // If EntityManager is closed, abort the import
                    if (!$this->entityManager->isOpen()) {
                        $this->logger->error('EntityManager closed during import, aborting');
                        throw new ImportException(
                            ImportException::ERROR_IMPORT_FAILED,
                            sprintf('Import interrompu à la ligne %d : %s', $rowNumber, $e->getMessage()),
                            $e,
                        );
                    }
                }

                ++$batchCount;
                if ($batchCount >= self::BATCH_SIZE) {
                    $this->entityManager->flush();
                    $batchCount = 0;
                }
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            $executionTime = microtime(true) - $startTime;

            $result = new ImportResult(
                success: true,
                totalProcessed: \count($rows),
                productsCreated: $productsCreated,
                productsUpdated: $productsUpdated,
                mercurialesCreated: $mercurialesCreated,
                mercurialesUpdated: $mercurialesUpdated,
                skipped: $skipped,
                failed: $failed,
                errors: $errors,
                executionTime: $executionTime,
            );

            // Update import status
            $import->setImportResult($result->toArray());
            $import->setStatus(StatutImport::COMPLETED);
            $this->entityManager->flush();

            $this->logger->info('Import completed', [
                'importId' => $import->getIdAsString(),
                'productsCreated' => $productsCreated,
                'mercurialesCreated' => $mercurialesCreated,
                'executionTime' => round($executionTime, 2),
            ]);

            return $result;
        } catch (\Exception $e) {
            // Try to rollback if transaction is still active
            try {
                if ($this->entityManager->getConnection()->isTransactionActive()) {
                    $this->entityManager->rollback();
                }
            } catch (\Exception $rollbackException) {
                $this->logger->warning('Could not rollback transaction', [
                    'error' => $rollbackException->getMessage(),
                ]);
            }

            // Reset EntityManager if closed
            if (!$this->entityManager->isOpen()) {
                $this->entityManager = $this->managerRegistry->resetManager();
                // Note: import entity is now detached but we only need to save status
                // so we'll just log the error and let the import remain in its current state
                $this->logger->warning('EntityManager was reset, import status may not be saved');
            }

            // Try to save the failed status
            try {
                if ($import !== null) {
                    $import->setStatus(StatutImport::FAILED);
                    $import->setImportResult([
                        'error' => $e->getMessage(),
                    ]);
                    $this->entityManager->flush();
                }
            } catch (\Exception $statusException) {
                $this->logger->warning('Could not save failed import status', [
                    'error' => $statusException->getMessage(),
                ]);
            }

            $this->logger->error('Import failed', [
                'importId' => $import?->getIdAsString() ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($e instanceof ImportException) {
                throw $e;
            }

            throw new ImportException(ImportException::ERROR_IMPORT_FAILED, null, $e);
        }
    }

    /**
     * @return array{action: string, mercuriale_action: ?string}
     */
    private function processRow(
        array $row,
        int $rowNumber,
        ColumnMappingConfig $config,
        Fournisseur $fournisseur,
        ?Etablissement $etablissement,
        Utilisateur $user,
        ?\App\Entity\Unite $defaultUnit,
    ): array {
        $mappedData = $this->columnMapper->mapRow($row, $config);

        // Validate - only check for blocking errors
        $validation = $this->columnMapper->validateMappedRow($mappedData);
        if (!$validation['valid']) {
            throw new \RuntimeException(implode(', ', array_map(
                fn ($e) => $e['message'],
                $validation['errors'],
            )));
        }

        // Generate code from designation if missing
        if (empty($mappedData['code_fournisseur']) && !empty($mappedData['designation'])) {
            $mappedData['code_fournisseur'] = $this->generateCodeFromDesignation($mappedData['designation']);
        }

        // Find or create product
        $product = $this->produitFournisseurRepository->findOneBy([
            'fournisseur' => $fournisseur,
            'codeFournisseur' => $mappedData['code_fournisseur'],
        ]);

        $productAction = 'product_updated';

        if ($product === null) {
            // Create new product
            $product = new ProduitFournisseur();
            $product->setFournisseur($fournisseur);
            $product->setCodeFournisseur($mappedData['code_fournisseur']);
            $productAction = 'product_created';
        }

        // Update product fields
        if (!empty($mappedData['designation'])) {
            $product->setDesignationFournisseur($mappedData['designation']);
        }

        // Set unit (use default if not found)
        $unit = null;
        if (!empty($mappedData['unite'])) {
            $unit = $this->columnMapper->resolveUnite($mappedData['unite']);
        }
        $product->setUniteAchat($unit ?? $defaultUnit);

        // Set conditionnement only if valid
        if (!empty($mappedData['conditionnement']) && is_numeric($mappedData['conditionnement'])) {
            $product->setConditionnement($mappedData['conditionnement']);
        }

        $this->entityManager->persist($product);

        // Handle mercuriale (price) - only if price is valid
        $hasValidPrice = !empty($mappedData['prix'])
            && is_numeric($mappedData['prix'])
            && (float) $mappedData['prix'] > 0;

        if (!$hasValidPrice) {
            // No valid price - just create/update the product without mercuriale
            return [
                'action' => $productAction,
                'mercuriale_action' => null,
            ];
        }

        $dateDebut = $mappedData['date_debut']
            ? new \DateTimeImmutable($mappedData['date_debut'])
            : new \DateTimeImmutable();

        $dateFin = $mappedData['date_fin']
            ? new \DateTimeImmutable($mappedData['date_fin'])
            : null;

        $mercurialeAction = null;

        // Only check for existing mercuriale if product already exists (has an ID)
        if ($productAction === 'product_updated' && $product->getId() !== null) {
            $existingMercuriale = $this->mercurialeRepository->findPrixValide(
                $product,
                $etablissement,
                $dateDebut,
            );

            if ($existingMercuriale !== null) {
                // Check if price is different
                if (bccomp($existingMercuriale->getPrixNegocie(), $mappedData['prix'], 4) === 0) {
                    // Same price, skip
                    return ['action' => 'skipped', 'mercuriale_action' => null];
                }

                // End the existing mercuriale
                $previousDay = $dateDebut->modify('-1 day');
                $existingMercuriale->setDateFin($previousDay);
                $mercurialeAction = 'updated';
            }
        }

        // Create new mercuriale
        $mercuriale = new Mercuriale();
        $mercuriale->setProduitFournisseur($product);
        $mercuriale->setEtablissement($etablissement);
        $mercuriale->setPrixNegocie($mappedData['prix']);
        $mercuriale->setDateDebut($dateDebut);
        $mercuriale->setDateFin($dateFin);
        $mercuriale->setCreatedBy($user);

        $this->entityManager->persist($mercuriale);

        if ($mercurialeAction === null) {
            $mercurialeAction = 'created';
        }

        return [
            'action' => $productAction,
            'mercuriale_action' => $mercurialeAction,
        ];
    }
}
