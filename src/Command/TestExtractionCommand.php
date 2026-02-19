<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\BonLivraisonRepository;
use App\Service\Ocr\BonLivraisonExtractorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:test-extraction', description: 'Test OCR extraction on a BL')]
class TestExtractionCommand extends Command
{
    public function __construct(
        private readonly BonLivraisonExtractorService $extractor,
        private readonly BonLivraisonRepository $blRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'BL ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (int) $input->getArgument('id');

        $bl = $this->blRepository->find($id);
        if (!$bl) {
            $io->error("BL #$id not found");
            return Command::FAILURE;
        }

        $io->title("Extraction BL #$id (image: {$bl->getImagePath()})");
        $io->info('Appel API Claude en cours...');

        $result = $this->extractor->extract($bl);

        $io->newLine();
        $io->writeln('<info>Success:</info> ' . ($result->success ? 'YES' : 'NO'));
        $io->writeln('<info>Confiance:</info> ' . ($result->confiance ?? '-'));
        $io->writeln('<info>Lignes:</info> ' . count($result->lignes ?? []));
        $io->writeln('<info>Temps:</info> ' . ($result->tempsExtraction ?? '-') . 's');

        if ($result->warnings) {
            $io->warning($result->warnings);
        }

        if ($result->produitsNonMatches) {
            $io->note('Produits non matchés: ' . implode(', ', $result->produitsNonMatches));
        }

        if ($result->donneesBrutes) {
            $d = $result->donneesBrutes;

            $io->section('Fournisseur');
            $io->writeln('Nom: ' . ($d['fournisseur']['nom'] ?? '-'));
            $io->writeln('Groupe: ' . ($d['fournisseur']['groupe'] ?? '-'));

            $io->section('Document');
            $doc = $d['document'] ?? [];
            $io->writeln('Numero: ' . ($doc['numero'] ?? '-'));
            $io->writeln('Date: ' . ($doc['date'] ?? '-'));
            $io->writeln('Client: ' . ($doc['client'] ?? '-'));

            $lignes = $d['lignes'] ?? [];
            $io->section("Lignes (" . count($lignes) . ")");

            $rows = [];
            foreach ($lignes as $l) {
                $rows[] = [
                    $l['numero_ligne'] ?? '',
                    $l['code_produit'] ?? '-',
                    mb_substr($l['designation'] ?? '-', 0, 30),
                    $l['origine'] ?? '',
                    ($l['quantite_livree'] ?? '-') . ' ' . ($l['unite_livraison'] ?? ''),
                    ($l['quantite_facturee'] ?? '-') . ' ' . ($l['unite_facturation'] ?? ''),
                    $l['prix_unitaire'] ?? '-',
                    $l['majoration_decote'] ?? '0',
                    $l['total_ht_ligne'] ?? '-',
                    $l['tva_code'] ?? '-',
                ];
            }
            $io->table(
                ['N°', 'Code', 'Désignation', 'Orig', 'Qté Liv', 'Qté Fact', 'PU', 'MJ', 'Total', 'TVA'],
                $rows
            );

            $io->section('Totaux');
            $t = $d['totaux'] ?? [];
            $io->writeln('Colis: ' . ($t['nombre_colis'] ?? '-'));
            $io->writeln('Poids: ' . ($t['poids_total_kg'] ?? '-') . ' kg');
            $io->writeln('Total HT: ' . ($t['total_ht'] ?? '-'));
        }

        return $result->success ? Command::SUCCESS : Command::FAILURE;
    }
}
