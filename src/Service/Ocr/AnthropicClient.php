<?php

declare(strict_types=1);

namespace App\Service\Ocr;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AnthropicClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const MAX_RETRIES = 3;
    private const TIMEOUT_SECONDS = 60;
    private const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20 Mo

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'image/jpeg',
        'image/png' => 'image/png',
        'image/gif' => 'image/gif',
        'image/webp' => 'image/webp',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens,
    ) {
    }

    /**
     * Envoie une image à Claude et retourne la réponse.
     *
     * @param string $imagePath Chemin absolu vers l'image
     * @param string $prompt    Le prompt d'extraction
     *
     * @return array{content: string, usage: array{input_tokens: int, output_tokens: int}}
     *
     * @throws AnthropicApiException
     */
    public function analyzeImage(string $imagePath, string $prompt): array
    {
        $startTime = microtime(true);

        // 1. Valider que le fichier existe et est une image valide
        $this->validateImageFile($imagePath);

        // 2. Lire l'image et encoder en base64
        $imageData = $this->encodeImage($imagePath);

        // 3. Construire le payload
        $payload = $this->buildPayload($imageData['base64'], $imageData['media_type'], $prompt);

        // 4. Appeler l'API avec retry
        $response = $this->callApiWithRetry($payload);

        // 5. Logger le succès (sans les données sensibles)
        $duration = round(microtime(true) - $startTime, 2);
        $this->logger->info('Anthropic API call successful', [
            'duration_seconds' => $duration,
            'input_tokens' => $response['usage']['input_tokens'] ?? 0,
            'output_tokens' => $response['usage']['output_tokens'] ?? 0,
            'model' => $this->model,
        ]);

        // 6. Extraire et retourner le contenu
        return [
            'content' => $this->extractContent($response),
            'usage' => $response['usage'] ?? ['input_tokens' => 0, 'output_tokens' => 0],
        ];
    }

    /**
     * Valide que le fichier est une image valide et respecte les contraintes.
     *
     * @throws AnthropicApiException
     */
    private function validateImageFile(string $imagePath): void
    {
        if (!file_exists($imagePath)) {
            throw new AnthropicApiException("Le fichier n'existe pas: {$imagePath}");
        }

        if (!is_readable($imagePath)) {
            throw new AnthropicApiException("Le fichier n'est pas lisible: {$imagePath}");
        }

        $fileSize = filesize($imagePath);
        if ($fileSize === false || $fileSize > self::MAX_FILE_SIZE) {
            throw new AnthropicApiException(
                sprintf('Le fichier dépasse la taille maximale autorisée (%d Mo)', self::MAX_FILE_SIZE / 1024 / 1024)
            );
        }

        $mimeType = mime_content_type($imagePath);
        if ($mimeType === false || !isset(self::ALLOWED_MIME_TYPES[$mimeType])) {
            throw new AnthropicApiException(
                sprintf('Type de fichier non supporté: %s. Types autorisés: %s', $mimeType, implode(', ', array_keys(self::ALLOWED_MIME_TYPES)))
            );
        }
    }

    /**
     * Encode l'image en base64 et détecte le media type.
     *
     * @return array{base64: string, media_type: string}
     *
     * @throws AnthropicApiException
     */
    private function encodeImage(string $imagePath): array
    {
        $imageContent = file_get_contents($imagePath);
        if ($imageContent === false) {
            throw new AnthropicApiException("Impossible de lire le fichier: {$imagePath}");
        }

        $mimeType = mime_content_type($imagePath);
        if ($mimeType === false) {
            throw new AnthropicApiException("Impossible de déterminer le type MIME du fichier");
        }

        return [
            'base64' => base64_encode($imageContent),
            'media_type' => self::ALLOWED_MIME_TYPES[$mimeType],
        ];
    }

    /**
     * Construit le payload pour l'API Anthropic.
     */
    private function buildPayload(string $base64Image, string $mediaType, string $prompt): array
    {
        return [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mediaType,
                                'data' => $base64Image,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Appelle l'API avec retry et backoff exponentiel.
     *
     * @throws AnthropicApiException
     */
    private function callApiWithRetry(array $payload): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; ++$attempt) {
            try {
                return $this->callApi($payload);
            } catch (AnthropicApiException $e) {
                $lastException = $e;

                // Ne pas retry pour les erreurs client (4xx sauf 429)
                if ($e->getStatusCode() >= 400 && $e->getStatusCode() < 500 && $e->getStatusCode() !== 429) {
                    throw $e;
                }

                // Backoff exponentiel : 1s, 2s, 4s
                if ($attempt < self::MAX_RETRIES) {
                    $sleepSeconds = pow(2, $attempt - 1);
                    $this->logger->warning('Anthropic API call failed, retrying', [
                        'attempt' => $attempt,
                        'max_retries' => self::MAX_RETRIES,
                        'sleep_seconds' => $sleepSeconds,
                        'error' => $e->getMessage(),
                    ]);
                    sleep($sleepSeconds);
                }
            }
        }

        $this->logger->error('Anthropic API call failed after all retries', [
            'attempts' => self::MAX_RETRIES,
            'last_error' => $lastException?->getMessage(),
        ]);

        throw $lastException ?? new AnthropicApiException('Échec de l\'appel API après plusieurs tentatives');
    }

    /**
     * Effectue l'appel HTTP à l'API Anthropic.
     *
     * @throws AnthropicApiException
     */
    private function callApi(array $payload): array
    {
        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                ],
                'json' => $payload,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                $errorContent = $response->getContent(false);
                $errorData = json_decode($errorContent, true);
                $errorMessage = $errorData['error']['message'] ?? 'Erreur inconnue';

                throw new AnthropicApiException(
                    sprintf('Erreur API Anthropic (%d): %s', $statusCode, $errorMessage),
                    $statusCode
                );
            }

            $content = $response->getContent();
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new AnthropicApiException('Réponse API invalide: JSON malformé');
            }

            return $data;
        } catch (TransportExceptionInterface $e) {
            throw new AnthropicApiException(
                sprintf('Erreur de connexion à l\'API Anthropic: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Extrait le contenu texte de la réponse API.
     *
     * @throws AnthropicApiException
     */
    private function extractContent(array $response): string
    {
        if (!isset($response['content']) || !is_array($response['content'])) {
            throw new AnthropicApiException('Format de réponse API inattendu: content manquant');
        }

        foreach ($response['content'] as $block) {
            if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                return $block['text'];
            }
        }

        throw new AnthropicApiException('Aucun contenu texte dans la réponse API');
    }
}
