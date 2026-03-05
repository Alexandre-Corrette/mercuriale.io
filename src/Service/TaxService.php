<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organisation;
use App\Repository\FactureFournisseurRepository;

class TaxService
{
    public function __construct(
        private readonly FactureFournisseurRepository $factureRepo,
    ) {
    }

    /**
     * @return array{
     *     period_label: string,
     *     from: \DateTimeImmutable,
     *     to: \DateTimeImmutable,
     *     lines: array<int, array{taux: string, montantHt: string, montantTva: string}>,
     *     totalHt: string,
     *     totalTva: string
     * }
     */
    public function computeVatForPeriod(
        Organisation $organisation,
        int $year,
        int $month,
        string $periodicity = 'monthly',
    ): array {
        if ($periodicity === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $startMonth = ($quarter - 1) * 3 + 1;
            $endMonth = $startMonth + 2;
            $from = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $startMonth));
            $to = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $endMonth));
            $to = $to->modify('last day of this month');
            $periodLabel = sprintf('T%d %d', $quarter, $year);
        } else {
            $from = new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
            $to = $from->modify('last day of this month');
            $periodLabel = $from->format('F Y');
        }

        $vatLines = $this->factureRepo->sumVatByRateForOrganisation($organisation, $from, $to);

        $totalHt = '0.00';
        $totalTva = '0.00';

        foreach ($vatLines as &$line) {
            $totalHt = bcadd($totalHt, $line['montantHt'] ?? '0', 2);
            $totalTva = bcadd($totalTva, $line['montantTva'] ?? '0', 2);
        }

        return [
            'period_label' => $periodLabel,
            'from' => $from,
            'to' => $to,
            'lines' => $vatLines,
            'totalHt' => $totalHt,
            'totalTva' => $totalTva,
        ];
    }
}
