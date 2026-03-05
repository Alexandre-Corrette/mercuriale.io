<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message dispatched to fetch pending invoices from B2Brouter for an establishment.
 */
final readonly class FetchPendingInvoicesMessage
{
    public function __construct(
        public int $etablissementId,
    ) {
    }
}
