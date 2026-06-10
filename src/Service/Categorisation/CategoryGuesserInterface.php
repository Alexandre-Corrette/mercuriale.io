<?php

declare(strict_types=1);

namespace App\Service\Categorisation;

/**
 * Devine la famille (catégorie) d'un produit à partir de sa désignation.
 *
 * Implémentations prévues, interchangeables derrière cette interface :
 *  - KeywordCategoryGuesser : règles mots-clés déterministes (gratuit, baseline).
 *  - (futur) ClaudeCategoryGuesser : fallback LLM pour la traîne non couverte.
 *  - (futur) ModelCategoryGuesser : modèle maison entraîné (labo mercure.io).
 */
interface CategoryGuesserInterface
{
    /**
     * @param string $designation Libellé brut du produit (ex. "*FETA AOP CUBES 900G MC")
     *
     * @return string|null Le CODE de catégorie deviné (ex. "CREMERIE"),
     *                     ou null si indéterminé (à laisser non catégorisé / revue humaine).
     */
    public function guess(string $designation): ?string;
}
