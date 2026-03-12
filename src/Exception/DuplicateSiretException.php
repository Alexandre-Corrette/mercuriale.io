<?php

declare(strict_types=1);

namespace App\Exception;

class DuplicateSiretException extends \DomainException
{
    public function __construct(string $siret)
    {
        parent::__construct(sprintf('Un établissement avec le SIRET %s existe déjà.', $siret));
    }
}
