<?php

declare(strict_types=1);

namespace App\Exception;

class NoActiveOrganisationException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Aucune organisation active en session.');
    }
}
