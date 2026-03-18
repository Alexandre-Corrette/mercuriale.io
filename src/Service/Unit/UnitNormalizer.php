<?php

declare(strict_types=1);

namespace App\Service\Unit;

/**
 * Unified unit normalizer for both import (Excel/CSV) and OCR flows.
 * Canonical format: uppercase codes matching the Unite entity codes used
 * in MercurialeFixtures and BonLivraisonExtractorService.
 */
class UnitNormalizer
{
    /**
     * Exhaustive mapping from raw input strings to canonical uppercase codes.
     * Covers: French, English, abbreviations, plurals, accented forms.
     *
     * @var array<string, string>
     */
    private const MAPPING = [
        // ── Poids ──
        'kg' => 'KG',
        'kilo' => 'KG',
        'kilos' => 'KG',
        'kilogramme' => 'KG',
        'kilogrammes' => 'KG',
        'g' => 'G',
        'gr' => 'G',
        'gramme' => 'G',
        'grammes' => 'G',
        'mg' => 'G',
        'milligramme' => 'G',
        'tonne' => 'KG',
        't' => 'KG',

        // ── Volume ──
        'l' => 'L',
        'lt' => 'L',
        'litre' => 'L',
        'litres' => 'L',
        'cl' => 'CL',
        'centilitre' => 'CL',
        'centilitres' => 'CL',
        'ml' => 'ML',
        'millilitre' => 'ML',
        'millilitres' => 'ML',
        'dl' => 'L',
        'décilitre' => 'L',
        'decilitre' => 'L',
        'hl' => 'L',
        'hectolitre' => 'L',

        // ── Pièce / unité ──
        'p' => 'PU',
        'pc' => 'PU',
        'pce' => 'PU',
        'piece' => 'PU',
        'pièce' => 'PU',
        'pieces' => 'PU',
        'pièces' => 'PU',
        'pu' => 'PU',
        'u' => 'UNI',
        'uni' => 'UNI',
        'unite' => 'UNI',
        'unité' => 'UNI',
        'unites' => 'UNI',
        'unités' => 'UNI',
        'uvc' => 'PU',
        'uvp' => 'PU',
        'lot' => 'LOT',
        'lots' => 'LOT',
        'dz' => 'PU',
        'douzaine' => 'PU',
        'pqt' => 'PU',
        'paquet' => 'PU',
        'paquets' => 'PU',

        // ── Conditionnements ──
        'bq' => 'BQT',
        'bqt' => 'BQT',
        'barquette' => 'BQT',
        'barquettes' => 'BQT',
        'bouquet' => 'BQT',
        'bt' => 'BOT',
        'bot' => 'BOT',
        'btl' => 'BOT',
        'bouteille' => 'BOT',
        'bouteilles' => 'BOT',
        'flacon' => 'BOT',
        'flacons' => 'BOT',
        'fl' => 'BOT',
        'ct' => 'CAR',
        'crt' => 'CAR',
        'car' => 'CAR',
        'carton' => 'CAR',
        'cartons' => 'CAR',
        'caisse' => 'CAR',
        'caisses' => 'CAR',
        'col' => 'COL',
        'colis' => 'COL',
        'flt' => 'COL',
        'filet' => 'COL',
        'sac' => 'SAC',
        'sacs' => 'SAC',
        'sachet' => 'SAC',
        'sachets' => 'SAC',
        'bag' => 'SAC',
        'fut' => 'FUT',
        'fût' => 'FUT',
        'futs' => 'FUT',
        'bte' => 'BTE',
        'boite' => 'BTE',
        'boîte' => 'BTE',
        'boites' => 'BTE',
        'boîtes' => 'BTE',
        'plt' => 'PLT',
        'plateau' => 'PLT',
        'plateaux' => 'PLT',
        'pal' => 'PAL',
        'palette' => 'PAL',
        'palettes' => 'PAL',
        'pck' => 'PCK',
        'pack' => 'PCK',
        'packs' => 'PCK',
        'bdn' => 'BDN',
        'bidon' => 'BDN',
        'bidons' => 'BDN',
        'jer' => 'JER',
        'jerrycan' => 'JER',
        'bac' => 'PU',
        'bacs' => 'PU',
        'rouleau' => 'PU',
        'rouleaux' => 'PU',
        'rlx' => 'PU',
    ];

    /**
     * Normalize a raw unit string to the canonical uppercase code.
     *
     * @param bool $strict If true, throws UnitNormalizerException for unknown values.
     *                     If false, returns strtoupper($raw) as fallback.
     *
     * @throws UnitNormalizerException If $strict is true and the value is unknown
     */
    public static function normalize(string $raw, bool $strict = false): string
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            if ($strict) {
                throw new UnitNormalizerException('Empty unit value');
            }

            return '';
        }

        $lower = mb_strtolower($trimmed);

        if (isset(self::MAPPING[$lower])) {
            return self::MAPPING[$lower];
        }

        // Check if already a valid canonical code (e.g. 'KG', 'COL')
        $upper = strtoupper($trimmed);
        if (\in_array($upper, self::MAPPING, true)) {
            return $upper;
        }

        if ($strict) {
            throw new UnitNormalizerException(sprintf('Unknown unit: "%s"', $raw));
        }

        return $upper;
    }

    /**
     * Map a canonical code to the database code (Unite entity code).
     * Most codes match directly; this handles the few exceptions.
     *
     * @var array<string, string>
     */
    private const CANONICAL_TO_DB = [
        'PU' => 'p',
        'BOT' => 'bt',
        'BQT' => 'bq',
        'CAR' => 'ct',
        'LOT' => 'lot',
        'KG' => 'kg',
        'G' => 'g',
        'L' => 'L',
        'CL' => 'cL',
        'ML' => 'mL',
    ];

    /**
     * Convert a canonical code to the database Unite code.
     * Falls back to the canonical code if no specific mapping exists.
     */
    public static function toDbCode(string $canonical): string
    {
        return self::CANONICAL_TO_DB[$canonical] ?? $canonical;
    }
}
