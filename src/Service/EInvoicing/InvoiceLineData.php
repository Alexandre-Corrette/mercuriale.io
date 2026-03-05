<?php

declare(strict_types=1);

namespace App\Service\EInvoicing;

/**
 * DTO representing a single line of an invoice fetched from the PDP.
 */
final readonly class InvoiceLineData
{
    public function __construct(
        public ?string $externalId,
        public ?string $productCode,
        public string $description,
        public string $quantity,
        public string $unitPrice,
        public string $lineTotal,
        public ?string $vatRate = null,
        public ?string $unit = null,
    ) {
    }
}
