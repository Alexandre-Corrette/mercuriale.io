<?php

declare(strict_types=1);

namespace App\Exception;

class OrganisationAccessRevokedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Votre accès à cette société a été révoqué.');
    }
}
