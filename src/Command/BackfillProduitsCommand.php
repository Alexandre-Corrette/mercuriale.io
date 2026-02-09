<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Produit;
use App\Repository\ProduitFournisseurRepository;
use App\Repository\ProduitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backfill-produits',
    description: 'Create Produit records from orphaned ProduitFournisseur rows (produit_id IS NULL)',
)]
class BackfillProduitsCommand extends Command
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProduitFournisseurRepository $produitFournisseurRepository,
        private readonly ProduitRepository $produitRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be created without persisting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        $orphans = $this->produitFournisseurRepository->findBy(['produit' => null]);

        if (\count($orphans) === 0) {
            $io->success('No orphaned ProduitFournisseur found. Nothing to do.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d ProduitFournisseur without linked Produit.', \count($orphans)));

        $created = 0;
        $linked = 0;
        $batchCount = 0;

        foreach ($orphans as $pf) {
            $designation = $pf->getDesignationFournisseur();
            if (empty($designation)) {
                $io->warning(sprintf('ProduitFournisseur #%d has no designation, skipping.', $pf->getId()));
                continue;
            }

            // Check if a Produit with this name already exists
            $produit = $this->produitRepository->findOneBy(['nom' => $designation]);

            if ($produit === null) {
                $produit = new Produit();
                $produit->setNom($designation);
                $produit->setUniteBase($pf->getUniteAchat());
                $produit->setCodeInterne($pf->getCodeFournisseur());

                if (!$dryRun) {
                    $this->entityManager->persist($produit);
                }
                ++$created;
            } else {
                ++$linked;
            }

            if (!$dryRun) {
                $pf->setProduit($produit);
            }

            ++$batchCount;
            if (!$dryRun && $batchCount >= self::BATCH_SIZE) {
                $this->entityManager->flush();
                $batchCount = 0;
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $total = $created + $linked;
        $prefix = $dryRun ? '[DRY-RUN] Would have' : 'Successfully';

        $io->success(sprintf(
            '%s processed %d ProduitFournisseur: %d new Produit created, %d linked to existing Produit.',
            $prefix,
            $total,
            $created,
            $linked,
        ));

        return Command::SUCCESS;
    }
}
