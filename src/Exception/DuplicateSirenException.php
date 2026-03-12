<?php

declare(strict_types=1);

namespace App\Exception;

class DuplicateSirenException extends \DomainException
{
    public function __construct(string $siren)
    {
        parent::__construct(sprintf('Une organisation avec le SIREN %s existe déjà.', $siren));
    }
}
