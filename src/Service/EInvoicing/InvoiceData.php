<?php

declare(strict_types=1);

namespace App\Service\EInvoicing;

/**
 * DTO representing an invoice fetched from the PDP (B2Brouter).
 */
final readonly class InvoiceData
{
    /**
     * @param InvoiceLineData[] $lines
     */
    public function __construct(
        public string $externalId,
        public string $invoiceNumber,
        public \DateTimeImmutable $issueDate,
        public ?string $supplierName,
        public ?string $supplierVat,
        public ?string $supplierSiren,
        public ?string $buyerName,
        public ?string $buyerVat,
        public string $totalExclTax,
        public string $totalVat,
        public string $totalInclTax,
        public ?string $currency = 'EUR',
        public ?string $status = null,
        public array $lines = [],
    ) {
    }

    /**
     * Extracts SIREN (9 digits) from a French VAT number.
     *
     * French VAT format: FR + 2-digit key + 9-digit SIREN
     * Example: FR32823456789 → 823456789
     *
     * Returns null for non-French VAT numbers or invalid formats.
     */
    public static function extractSirenFromFrenchVat(?string $vatNumber): ?string
    {
        if ($vatNumber === null) {
            return null;
        }

        $cleaned = strtoupper(str_replace(' ', '', $vatNumber));

        if (preg_match('/^FR\d{2}(\d{9})$/', $cleaned, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
