<?php

declare(strict_types=1);

namespace App\Service\Mercuriale;

use App\DTO\Import\ImportResult;
use App\Entity\MercurialeImport;
use App\Entity\Utilisateur;
use App\Service\Import\MercurialeBulkImporter;
use Psr\Log\LoggerInterface;

class ExecuteImportService
{
    public function __construct(
        private readonly MercurialeBulkImporter $bulkImporter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Execute a previewed mercuriale import.
     */
    public function execute(MercurialeImport $import, Utilisateur $user): ImportResult
    {
        $result = $this->bulkImporter->execute($import, $user);

        $this->logger->info('Import mercuriale termine', [
            'importId' => $import->getIdAsString(),
            'productsCreated' => $result->productsCreated,
            'mercurialesCreated' => $result->mercurialesCreated,
            'failed' => $result->failed,
        ]);

        return $result;
    }
}
