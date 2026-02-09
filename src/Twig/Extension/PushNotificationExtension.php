<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PushNotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly string $vapidPublicKey,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('vapid_public_key', $this->getVapidPublicKey(...)),
        ];
    }

    public function getVapidPublicKey(): string
    {
        return $this->vapidPublicKey;
    }
}
