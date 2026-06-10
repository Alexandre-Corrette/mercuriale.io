<?php

declare(strict_types=1);

namespace App\Service\Categorisation;

use App\Repository\CategorieProduitRepository;
use App\Service\Ocr\AnthropicClient;
use Psr\Log\LoggerInterface;

/**
 * Fallback LLM : classe une désignation dans la taxonomie existante via Claude
 * (texte seul, sortie contrainte aux codes présents en base). Pensé pour la
 * traîne non couverte par les règles mots-clés.
 *
 * Préférer guessBatch() : un seul appel API classe des dizaines de produits
 * (coût ~nul vs un appel par produit).
 */
final class ClaudeCategoryGuesser implements CategoryGuesserInterface
{
    public function __construct(
        private readonly AnthropicClient $anthropic,
        private readonly CategorieProduitRepository $categorieRepo,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function guess(string $designation): ?string
    {
        return $this->guessBatch([$designation])[0] ?? null;
    }

    /**
     * @param list<string> $designations
     *
     * @return array<int, string|null> Même indexation que l'entrée ; code catégorie valide ou null.
     */
    public function guessBatch(array $designations): array
    {
        if ($designations === []) {
            return [];
        }

        // Codes autorisés = taxonomie réellement en base (la sortie est contrainte à ça).
        $allowed = [];
        foreach ($this->categorieRepo->findAll() as $cat) {
            $allowed[$cat->getCode()] = $cat->getNom();
        }
        if ($allowed === []) {
            return array_fill(0, count($designations), null);
        }

        $result = array_fill(0, count($designations), null);

        try {
            $raw = $this->anthropic->analyzeText(
                $this->buildPrompt($allowed, $designations),
                maxTokens: 2048,
            );
            $parsed = $this->parse($raw['content']);

            foreach ($parsed as $i => $code) {
                // i est 1-based dans la réponse ; on revient en 0-based.
                $idx = $i - 1;
                if ($idx >= 0 && $idx < count($designations) && isset($allowed[$code])) {
                    $result[$idx] = $code;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('ClaudeCategoryGuesser: échec classification batch', [
                'count' => count($designations),
                'error' => $e->getMessage(),
            ]);
            // On retourne tout en null : la commande laisse ces produits non catégorisés.
        }

        return $result;
    }

    /**
     * @param array<string, string> $allowed code => libellé
     * @param list<string>           $designations
     */
    private function buildPrompt(array $allowed, array $designations): string
    {
        $cats = [];
        foreach ($allowed as $code => $nom) {
            $cats[] = sprintf('- %s : %s', $code, $nom);
        }

        $lignes = [];
        foreach ($designations as $i => $designation) {
            $lignes[] = sprintf('%d. %s', $i + 1, $designation);
        }

        return <<<PROMPT
            Tu classes des produits achetés par un restaurant dans une taxonomie FIXE.

            Catégories autorisées (CODE : libellé) :
            {$this->join($cats)}

            Règles :
            - Réponds UNIQUEMENT avec un tableau JSON, sans texte autour.
            - Format : [{"i": <numéro du produit>, "code": "<CODE>"}, ...]
            - "code" doit être EXACTEMENT l'un des codes ci-dessus. N'invente jamais de code.
            - Si aucune catégorie ne convient vraiment, utilise "code": "AUTRE".
            - Un seul code par produit.

            Produits à classer :
            {$this->join($lignes)}
            PROMPT;
    }

    /** @param list<string> $items */
    private function join(array $items): string
    {
        return implode("\n", $items);
    }

    /**
     * Extrait le tableau JSON de la réponse et renvoie une map numéro(1-based) => code.
     *
     * @return array<int, string>
     */
    private function parse(string $content): array
    {
        $start = strpos($content, '[');
        $end = strrpos($content, ']');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $json = substr($content, $start, $end - $start + 1);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        $map = [];
        foreach ($data as $row) {
            if (is_array($row) && isset($row['i'], $row['code']) && is_numeric($row['i'])) {
                $map[(int) $row['i']] = (string) $row['code'];
            }
        }

        return $map;
    }
}
