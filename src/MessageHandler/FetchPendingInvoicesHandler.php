<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Etablissement;
use App\Entity\FactureFournisseur;
use App\Entity\LigneFactureFournisseur;
use App\Message\FetchPendingInvoicesMessage;
use App\Repository\FactureFournisseurRepository;
use App\Repository\FournisseurRepository;
use App\Service\EInvoicing\InvoiceData;
use App\Service\EInvoicing\InvoiceMatchingService;
use App\Service\EInvoicing\PdpClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FetchPendingInvoicesHandler
{
    public function __construct(
        private readonly PdpClientInterface $pdpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly FactureFournisseurRepository $factureRepo,
        private readonly FournisseurRepository $fournisseurRepo,
        private readonly InvoiceMatchingService $matchingService,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(FetchPendingInvoicesMessage $message): void
    {
        $etablissement = $this->entityManager->find(Etablissement::class, $message->etablissementId);
        if ($etablissement === null) {
            $this->logger->error('[EInvoicing] Établissement introuvable', [
                'etablissement_id' => $message->etablissementId,
            ]);

            return;
        }

        $accountId = $etablissement->getPdpAccountId();
        if ($accountId === null || !$etablissement->isEInvoicingEnabled()) {
            $this->logger->warning('[EInvoicing] Établissement non inscrit à la facturation électronique', [
                'etablissement_id' => $etablissement->getId(),
            ]);

            return;
        }

        $this->logger->info('[EInvoicing] Récupération des factures en attente', [
            'etablissement_id' => $etablissement->getId(),
            'pdp_account_id' => $accountId,
        ]);

        $pendingInvoices = $this->pdpClient->fetchPendingInvoices($accountId);

        $imported = 0;
        $skipped = 0;

        foreach ($pendingInvoices as $invoiceData) {
            // Deduplication by externalId
            if ($this->factureRepo->findByExternalId($invoiceData->externalId) !== null) {
                ++$skipped;
                continue;
            }

            $facture = $this->importInvoice($invoiceData, $etablissement);
            ++$imported;

            // Attempt automatic rapprochement with BL
            if ($facture !== null) {
                $this->matchingService->matchFacture($facture);
            }
        }

        $this->logger->info('[EInvoicing] Import terminé', [
            'etablissement_id' => $etablissement->getId(),
            'total' => \count($pendingInvoices),
            'imported' => $imported,
            'skipped' => $skipped,
        ]);
    }

    private function importInvoice(InvoiceData $invoiceData, Etablissement $etablissement): ?FactureFournisseur
    {
        // Fetch detailed invoice with lines
        $detailed = $this->pdpClient->getInvoiceWithLines($invoiceData->externalId);

        $importedFacture = null;
        $this->entityManager->wrapInTransaction(function () use ($detailed, $etablissement, &$importedFacture): void {
            $facture = new FactureFournisseur();
            $facture->setExternalId($detailed->externalId);
            $facture->setNumeroFacture($detailed->invoiceNumber);
            $facture->setDateEmission($detailed->issueDate);
            $facture->setEtablissement($etablissement);
            $facture->setFournisseurNom($detailed->supplierName);
            $facture->setFournisseurTva($detailed->supplierVat);
            $facture->setFournisseurSiren($detailed->supplierSiren);
            $facture->setAcheteurNom($detailed->buyerName);
            $facture->setAcheteurTva($detailed->buyerVat);
            $facture->setMontantHt($detailed->totalExclTax);
            $facture->setMontantTva($detailed->totalVat);
            $facture->setMontantTtc($detailed->totalInclTax);
            $facture->setDevise($detailed->currency ?? 'EUR');

            // Auto-detect fournisseur by SIREN
            $fournisseur = $this->matchFournisseur($detailed, $etablissement);
            if ($fournisseur !== null) {
                $facture->setFournisseur($fournisseur);
            }

            // Map lines
            foreach ($detailed->lines as $lineData) {
                $ligne = new LigneFactureFournisseur();
                $ligne->setExternalId($lineData->externalId);
                $ligne->setCodeArticle($lineData->productCode);
                $ligne->setDesignation($lineData->description);
                $ligne->setQuantite($lineData->quantity);
                $ligne->setPrixUnitaire($lineData->unitPrice);
                $ligne->setMontantLigne($lineData->lineTotal);
                $ligne->setTauxTva($lineData->vatRate);
                $ligne->setUnite($lineData->unit);
                $facture->addLigne($ligne);
            }

            $this->entityManager->persist($facture);

            // Archive original document
            $this->archiveDocument($detailed->externalId, $facture);

            $importedFacture = $facture;
        });

        // Acknowledge only after successful import
        $this->pdpClient->acknowledgeInvoice($detailed->externalId);

        $this->logger->info('[EInvoicing] Facture importée et acquittée', [
            'external_id' => $detailed->externalId,
            'numero' => $detailed->invoiceNumber,
            'fournisseur' => $detailed->supplierName,
            'montant_ht' => $detailed->totalExclTax,
        ]);

        return $importedFacture;
    }

    /**
     * Auto-detects the Fournisseur by matching SIREN from the supplier VAT number
     * against the SIRET of known fournisseurs (SIREN = first 9 digits of SIRET).
     */
    private function matchFournisseur(InvoiceData $invoiceData, Etablissement $etablissement): ?\App\Entity\Fournisseur
    {
        if ($invoiceData->supplierSiren === null) {
            return null;
        }

        $org = $etablissement->getOrganisation();
        if ($org === null) {
            return null;
        }

        $fournisseurs = $this->fournisseurRepo->findByOrganisation($org);

        foreach ($fournisseurs as $fournisseur) {
            $siret = $fournisseur->getSiret();
            if ($siret !== null && str_starts_with($siret, $invoiceData->supplierSiren)) {
                return $fournisseur;
            }
        }

        return null;
    }

    private function archiveDocument(string $externalId, FactureFournisseur $facture): void
    {
        try {
            $content = $this->pdpClient->getOriginalDocument($externalId);

            $dir = $this->projectDir . '/var/invoices/' . date('Y/m');
            if (!is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }

            $filename = sprintf('%s_%s.pdf', $facture->getIdAsString(), $externalId);
            $path = $dir . '/' . $filename;
            file_put_contents($path, $content);

            $facture->setDocumentOriginalPath('var/invoices/' . date('Y/m') . '/' . $filename);
        } catch (\Throwable $e) {
            // Non-blocking: log but don't fail the import
            $this->logger->warning('[EInvoicing] Impossible d\'archiver le document original', [
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
