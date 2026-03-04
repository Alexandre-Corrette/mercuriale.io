<?php

declare(strict_types=1);

namespace App\Service\EInvoicing;

/**
 * Interface for PDP (Plateforme de Dématérialisation Partenaire) API client.
 *
 * Decoupled from any concrete implementation (B2Brouter, Chorus Pro, etc.).
 */
interface PdpClientInterface
{
    /**
     * Registers a company on the PDP.
     *
     * @return string The PDP account ID
     *
     * @throws PdpApiException
     */
    public function registerCompany(
        string $vatNumber,
        string $companyName,
        string $address,
        string $postalCode,
        string $city,
        string $country = 'FR',
    ): string;

    /**
     * Enables invoice reception transports for a registered account.
     *
     * @throws PdpApiException
     */
    public function enableReception(string $accountId): void;

    /**
     * Fetches pending invoices waiting to be processed.
     *
     * @return InvoiceData[]
     *
     * @throws PdpApiException
     */
    public function fetchPendingInvoices(string $accountId): array;

    /**
     * Fetches a single invoice with its line details.
     *
     * @throws PdpApiException
     */
    public function getInvoiceWithLines(string $invoiceId): InvoiceData;

    /**
     * Fetches the original document (PDF) for an invoice.
     *
     * @return string Binary content of the document
     *
     * @throws PdpApiException
     */
    public function getOriginalDocument(string $invoiceId): string;

    /**
     * Acknowledges receipt of an invoice (marks as received on the PDP).
     *
     * @throws PdpApiException
     */
    public function acknowledgeInvoice(string $invoiceId): void;

    /**
     * Updates the status of an invoice on the PDP.
     *
     * @param string $status One of: accepted, refused, paid
     *
     * @throws PdpApiException
     */
    public function updateInvoiceStatus(string $invoiceId, string $status, ?string $reason = null): void;
}
