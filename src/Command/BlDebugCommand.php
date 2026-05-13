<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BonLivraison;
use App\Entity\LigneBonLivraison;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:bl:debug',
    description: 'Diagnostic des bons de livraison en base',
)]
class BlDebugCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('bl_id', InputArgument::OPTIONAL, 'ID du BL à inspecter en détail')
            ->addOption('statut', 's', InputOption::VALUE_REQUIRED, 'Filtrer par statut (BROUILLON, EN_COURS_OCR, ECHEC_OCR, VALIDE, ANOMALIE, DOUBLON, ARCHIVE)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Nombre de BL à afficher', '20')
            ->addOption('orphans', null, InputOption::VALUE_NONE, 'Afficher uniquement les BL sans lignes')
            ->addOption('delete', null, InputOption::VALUE_REQUIRED, 'Supprimer un BL par son ID')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $conn = $this->entityManager->getConnection();

        // Mode suppression
        $deleteId = $input->getOption('delete');
        if ($deleteId !== null) {
            return $this->deleteBl($io, (int) $deleteId);
        }

        // Mode détail
        $blId = $input->getArgument('bl_id');
        if ($blId !== null) {
            return $this->showDetail($io, (int) $blId, $conn);
        }

        // Mode liste
        return $this->showList($io, $input, $conn);
    }

    private function showList(SymfonyStyle $io, InputInterface $input, \Doctrine\DBAL\Connection $conn): int
    {
        $limit = (int) $input->getOption('limit');
        $statut = $input->getOption('statut');
        $orphansOnly = $input->getOption('orphans');

        // Résumé par statut
        $counts = $conn->fetchAllAssociative('SELECT statut, COUNT(*) as nb FROM bon_livraison GROUP BY statut ORDER BY statut');
        $io->section('Résumé par statut');
        $table = new Table($output = $io);
        $table->setHeaders(['Statut', 'Nombre']);
        foreach ($counts as $row) {
            $table->addRow([$row['statut'], $row['nb']]);
        }
        $table->render();
        $io->newLine();

        // Liste des BL
        $sql = <<<'SQL'
            SELECT bl.id, bl.numero_bl, bl.statut, bl.date_livraison, bl.total_ht,
                   bl.created_at, bl.notes,
                   f.nom AS fournisseur,
                   e.nom AS etablissement,
                   (SELECT COUNT(*) FROM ligne_bon_livraison lbl WHERE lbl.bon_livraison_id = bl.id) AS nb_lignes
            FROM bon_livraison bl
            LEFT JOIN fournisseur f ON f.id = bl.fournisseur_id
            LEFT JOIN etablissement e ON e.id = bl.etablissement_id
        SQL;

        $params = [];
        $conditions = [];

        if ($statut !== null) {
            $conditions[] = 'bl.statut = :statut';
            $params['statut'] = strtoupper($statut);
        }

        if ($orphansOnly) {
            $conditions[] = '(SELECT COUNT(*) FROM ligne_bon_livraison lbl WHERE lbl.bon_livraison_id = bl.id) = 0';
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY bl.id DESC LIMIT ' . $limit;

        $rows = $conn->fetchAllAssociative($sql, $params);

        if ($rows === []) {
            $io->warning('Aucun BL trouvé.');

            return Command::SUCCESS;
        }

        $io->section(sprintf('Derniers BL (%d)', count($rows)));
        $table = new Table($io);
        $table->setHeaders(['ID', 'N° BL', 'Statut', 'Fournisseur', 'Date', 'Total HT', 'Lignes', 'Notes']);
        foreach ($rows as $row) {
            $table->addRow([
                $row['id'],
                $row['numero_bl'] ?? '-',
                $row['statut'],
                mb_substr($row['fournisseur'] ?? '-', 0, 20),
                $row['date_livraison'] ?? '-',
                $row['total_ht'] !== null ? number_format((float) $row['total_ht'], 2, ',', ' ') . ' €' : '-',
                $row['nb_lignes'],
                mb_substr($row['notes'] ?? '', 0, 40),
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }

    private function showDetail(SymfonyStyle $io, int $blId, \Doctrine\DBAL\Connection $conn): int
    {
        $bl = $conn->fetchAssociative(
            <<<'SQL'
                SELECT bl.*, f.nom AS fournisseur, e.nom AS etablissement
                FROM bon_livraison bl
                LEFT JOIN fournisseur f ON f.id = bl.fournisseur_id
                LEFT JOIN etablissement e ON e.id = bl.etablissement_id
                WHERE bl.id = :id
            SQL,
            ['id' => $blId],
        );

        if ($bl === false) {
            $io->error("BL #$blId introuvable.");

            return Command::FAILURE;
        }

        $io->section("BL #{$bl['id']}");
        $io->definitionList(
            ['N° BL' => $bl['numero_bl'] ?? '-'],
            ['N° Commande' => $bl['numero_commande'] ?? '-'],
            ['Statut' => $bl['statut']],
            ['Fournisseur' => $bl['fournisseur'] ?? '-'],
            ['Établissement' => $bl['etablissement'] ?? '-'],
            ['Date livraison' => $bl['date_livraison'] ?? '-'],
            ['Total HT' => $bl['total_ht'] !== null ? number_format((float) $bl['total_ht'], 2, ',', ' ') . ' €' : '-'],
            ['Image' => $bl['image_path'] ?? '-'],
            ['Créé le' => $bl['created_at'] ?? '-'],
            ['Validé le' => $bl['validated_at'] ?? '-'],
            ['Notes' => $bl['notes'] ?? '-'],
        );

        // Lignes
        $lignes = $conn->fetchAllAssociative(
            <<<'SQL'
                SELECT lbl.ordre, lbl.code_produit_bl, lbl.designation_bl, lbl.origine,
                       lbl.quantite_livree, lbl.unite_livraison,
                       lbl.quantite_facturee, lbl.unite_facturation,
                       lbl.prix_unitaire, lbl.total_ligne,
                       lbl.produit_fournisseur_id
                FROM ligne_bon_livraison lbl
                WHERE lbl.bon_livraison_id = :id
                ORDER BY lbl.ordre ASC
            SQL,
            ['id' => $blId],
        );

        if ($lignes === []) {
            $io->warning('Aucune ligne pour ce BL.');

            return Command::SUCCESS;
        }

        $io->section(sprintf('Lignes (%d)', count($lignes)));
        $table = new Table($io);
        $table->setHeaders(['#', 'Code', 'Désignation', 'Orig.', 'Qté Liv', 'U.Liv', 'Qté Fac', 'U.Fac', 'PU', 'Total', 'Matché']);
        foreach ($lignes as $l) {
            $table->addRow([
                $l['ordre'],
                $l['code_produit_bl'] ?? '-',
                mb_substr($l['designation_bl'] ?? '-', 0, 30),
                $l['origine'] ?? '-',
                $l['quantite_livree'] ?? '-',
                $l['unite_livraison'] ?? '-',
                $l['quantite_facturee'] ?? '-',
                $l['unite_facturation'] ?? '-',
                $l['prix_unitaire'] ?? '-',
                $l['total_ligne'] ?? '-',
                $l['produit_fournisseur_id'] !== null ? 'Oui' : 'Non',
            ]);
        }
        $table->render();

        // Données brutes OCR
        if (!empty($bl['donnees_brutes'])) {
            $io->section('Données brutes OCR (extrait)');
            $brutes = json_decode($bl['donnees_brutes'], true);
            if (isset($brutes['fournisseur'])) {
                $io->writeln('Fournisseur OCR : ' . ($brutes['fournisseur']['nom'] ?? '-'));
            }
            if (isset($brutes['document'])) {
                $io->writeln('N° BL OCR : ' . ($brutes['document']['numero'] ?? '-'));
                $io->writeln('Date OCR : ' . ($brutes['document']['date'] ?? '-'));
            }
            if (isset($brutes['confiance'])) {
                $io->writeln('Confiance : ' . $brutes['confiance']);
            }
            if (isset($brutes['remarques']) && $brutes['remarques'] !== []) {
                $io->writeln('Remarques OCR :');
                foreach ($brutes['remarques'] as $r) {
                    $io->writeln('  - ' . $r);
                }
            }
        }

        return Command::SUCCESS;
    }

    private function deleteBl(SymfonyStyle $io, int $blId): int
    {
        $bl = $this->entityManager->find(BonLivraison::class, $blId);

        if ($bl === null) {
            $io->error("BL #$blId introuvable.");

            return Command::FAILURE;
        }

        $io->warning(sprintf(
            'Suppression du BL #%d — N° %s — Statut %s — %d lignes',
            $bl->getId(),
            $bl->getNumeroBl() ?? '-',
            $bl->getStatut()->value,
            $bl->getLignes()->count(),
        ));

        if (!$io->confirm('Confirmer la suppression ?', false)) {
            $io->info('Annulé.');

            return Command::SUCCESS;
        }

        $this->entityManager->remove($bl);
        $this->entityManager->flush();

        $io->success("BL #$blId supprimé.");

        return Command::SUCCESS;
    }
}
