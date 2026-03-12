<?php

declare(strict_types=1);

namespace App\Entity;

enum PlanType: string
{
    case TRIAL = 'trial';
    case SINGLE = 'single';
    case MULTI = 'multi';
    case TESTPLAN = 'testplan';
}
