<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * MERC-32 — Validation des redirections pour prévenir les open redirects (OWASP A01)
 */
final class SafeRedirectHelper
{
    /**
     * Vérifie qu'une URL est sûre pour une redirection interne.
     * Retourne l'URL si valide, ou l'URL de fallback sinon.
     */
    public function getSafeRedirectUrl(
        string $url,
        Request $request,
        string $fallback = '/'
    ): string {
        $url = trim($url);

        // Chemin relatif interne : /foo/bar (pas // qui serait protocol-relative)
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        // URL absolue : vérifier que le host correspond exactement
        $parsed = parse_url($url);
        if (
            isset($parsed['host'])
            && $parsed['host'] === $request->getHost()
            && in_array($parsed['scheme'] ?? 'https', ['http', 'https'], true)
        ) {
            return $url;
        }

        // Toute autre valeur → fallback sécurisé
        return $fallback;
    }
}
