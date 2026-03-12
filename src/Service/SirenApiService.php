<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class SirenApiService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiUrl,
        private readonly string $clientId,
        private readonly string $apiSecret,
    ) {
    }

    /**
     * @return array{nom_complet: string, siren: string, siret: string|null, adresse: string|null, code_postal: string|null, ville: string|null, code_naf: string|null, tva_intracom: string|null}|null
     */
    public function lookup(string $query): ?array
    {
        $query = preg_replace('/\s+/', '', $query);

        if (!preg_match('/^\d{9}(\d{5})?$/', $query)) {
            return null;
        }

        $response = $this->httpClient->request('GET', $this->apiUrl . '/search', [
            'query' => [
                'q' => $query,
                'page' => 1,
                'per_page' => 1,
            ],
            'headers' => array_filter([
                'X-Client-Id' => $this->clientId ?: null,
            ]),
        ]);

        $data = $response->toArray();

        if (empty($data['results'])) {
            return null;
        }

        $result = $data['results'][0];
        $siege = $result['siege'] ?? [];
        $siren = $result['siren'] ?? $query;

        $enseigne = $siege['enseigne_1'] ?? $siege['enseigne_2'] ?? $siege['enseigne_3'] ?? null;

        return [
            'nom_complet' => $result['nom_complet'] ?? '',
            'siren' => $siren,
            'siret' => $siege['siret'] ?? null,
            'adresse' => trim(($siege['numero_voie'] ?? '') . ' ' . ($siege['type_voie'] ?? '') . ' ' . ($siege['libelle_voie'] ?? '')),
            'code_postal' => $siege['code_postal'] ?? null,
            'ville' => $siege['libelle_commune'] ?? null,
            'code_naf' => $result['activite_principale'] ?? null,
            'tva_intracom' => $this->computeVatNumber($siren),
            'enseigne' => $enseigne,
        ];
    }

    private function computeVatNumber(string $siren): ?string
    {
        if (strlen($siren) !== 9 || !ctype_digit($siren)) {
            return null;
        }

        $key = (12 + 3 * ((int) $siren % 97)) % 97;

        return sprintf('FR%02d%s', $key, $siren);
    }
}