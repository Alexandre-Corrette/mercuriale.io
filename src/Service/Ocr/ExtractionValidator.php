<?php

declare(strict_types=1);

namespace App\Service\Ocr;

/**
 * Validates OCR extraction results against business rules.
 * Returns a list of error codes that can trigger re-extraction or flag fields for human review.
 */
class ExtractionValidator
{
    /**
     * @return string[] List of validation error codes
     */
    public function validate(array $result): array
    {
        $errors = [];

        $errors = array_merge($errors, $this->validateDocument($result));
        $errors = array_merge($errors, $this->validateLignes($result));

        return $errors;
    }

    /**
     * Are the errors severe enough to warrant a re-extraction attempt?
     */
    public function isCritical(array $errors): bool
    {
        $critical = ['date_incoherente', 'aucune_ligne', 'numero_bl_format_invalide'];

        foreach ($errors as $error) {
            $code = explode(':', $error)[0];
            if (in_array($code, $critical, true)) {
                return true;
            }
        }

        return false;
    }

    private function validateDocument(array $result): array
    {
        $errors = [];
        $doc = $result['document'] ?? [];

        // Date coherence (2020-2030)
        if (isset($doc['date'])) {
            $year = (int) substr($doc['date'], 0, 4);
            if ($year < 2020 || $year > 2030) {
                $errors[] = 'date_incoherente:' . $doc['date'];
            }
        }

        // Numero BL must be alphanumeric and reasonable length
        if (isset($doc['numero'])) {
            if (!preg_match('/^[\w\-\/]{3,30}$/', $doc['numero'])) {
                $errors[] = 'numero_bl_format_invalide:' . $doc['numero'];
            }
        }

        // total_ht should not be a command number (>1M is suspicious)
        $totalHt = $result['totaux']['total_ht'] ?? null;
        if ($totalHt !== null && $totalHt > 100000) {
            $errors[] = 'total_ht_suspect:' . $totalHt;
        }

        // No lines extracted
        if (empty($result['lignes'])) {
            $errors[] = 'aucune_ligne';
        }

        return $errors;
    }

    private function validateLignes(array $result): array
    {
        $errors = [];
        $nullCount = 0;

        foreach ($result['lignes'] ?? [] as $i => $ligne) {
            // Rang should be a positive integer if present
            if (isset($ligne['rang']) && ($ligne['rang'] <= 0 || $ligne['rang'] > 9999)) {
                $errors[] = 'rang_invalide:' . $ligne['rang'];
            }

            // Total line coherence
            $qte = $ligne['quantite_facturee'] ?? null;
            $pu = $ligne['prix_unitaire'] ?? null;
            $total = $ligne['total_ht_ligne'] ?? null;
            $mj = $ligne['majoration_decote'] ?? 0;

            if ($qte !== null && $pu !== null && $total !== null && $total > 0) {
                $calcule = round(($qte * $pu) + $mj, 2);
                $ecart = abs($calcule - $total);
                if ($ecart > 0.10 && ($ecart / $total) > 0.05) {
                    $errors[] = sprintf('ecart_total_ligne_%d:%.2f', $i, $ecart);
                }
            }

            // Count null designations/codes
            if ($ligne['designation'] === null && ($ligne['code_produit'] ?? null) === null) {
                $nullCount++;
            }
        }

        // Low confidence if too many null fields
        if ($nullCount > 2) {
            $errors[] = 'trop_de_valeurs_nulles:' . $nullCount;
        }

        return $errors;
    }
}
