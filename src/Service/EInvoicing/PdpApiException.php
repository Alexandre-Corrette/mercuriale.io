<?php

declare(strict_types=1);

namespace App\Service\EInvoicing;

/**
 * Exception thrown when a PDP API call fails.
 */
class PdpApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatusCode = null,
        public readonly ?string $apiErrorCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function connectionFailed(string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Connexion à la PDP impossible : %s', $reason),
            previous: $previous,
        );
    }

    public static function requestFailed(int $httpStatus, string $body): self
    {
        return new self(
            sprintf('Erreur PDP (HTTP %d) : %s', $httpStatus, $body),
            httpStatusCode: $httpStatus,
        );
    }

    public static function invalidResponse(string $reason): self
    {
        return new self(
            sprintf('Réponse PDP invalide : %s', $reason),
        );
    }
}
