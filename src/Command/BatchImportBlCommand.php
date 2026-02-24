<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BonLivraison;
use App\Enum\StatutBonLivraison;
use App\Repository\EtablissementRepository;
use App\Repository\UtilisateurRepository;
use App\Service\Ocr\BonLivraisonExtractorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:batch-import-bl',
    description: 'Import BL images from a directory and optionally run OCR extraction',
)]
class BatchImportBlCommand extends Command
{
    private const ALLOWED_EXTENSIONS = ['jpeg', 'jpg', 'png', 'pdf'];
    private const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20 Mo

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'application/pdf',
    ];

    private const MAGIC_BYTES = [
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png' => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
        'application/pdf' => ['%PDF'],
    ];

    private const EXTENSION_MAP = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EtablissementRepository $etablissementRepository,
        private readonly UtilisateurRepository $utilisateurRepository,
        private readonly BonLivraisonExtractorService $extractorService,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::REQUIRED, 'Path to the folder containing BL images (JPEG/PNG/PDF)')
            ->addOption('etablissement', null, InputOption::VALUE_REQUIRED, 'Etablissement ID')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Utilisateur ID for createdBy (defaults to first ADMIN of the organisation)')
            ->addOption('extract', null, InputOption::VALUE_NONE, 'Also run OCR extraction after upload')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate files without persisting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $withExtract = (bool) $input->getOption('extract');
        $directory = $input->getArgument('directory');

        if ($dryRun) {
            $io->note('Mode dry-run : aucune donnée ne sera persistée.');
        }

        // 1. Validate directory
        if (!is_dir($directory) || !is_readable($directory)) {
            $io->error("Le répertoire '{$directory}' n'existe pas ou n'est pas lisible.");
            return Command::FAILURE;
        }

        // 2. Validate etablissement
        $etablissementId = $input->getOption('etablissement');
        if ($etablissementId === null) {
            $io->error("L'option --etablissement est obligatoire.");
            return Command::FAILURE;
        }

        $etablissement = $this->etablissementRepository->find((int) $etablissementId);
        if ($etablissement === null) {
            $io->error("Etablissement #{$etablissementId} introuvable.");
            return Command::FAILURE;
        }

        // 3. Resolve user
        $user = $this->resolveUser($input, $io, $etablissement);
        if ($user === null) {
            return Command::FAILURE;
        }

        $io->title('Import batch de BL');
        $io->info([
            "Répertoire : {$directory}",
            "Etablissement : {$etablissement->getNom()} (#{$etablissement->getId()})",
            "Utilisateur : {$user->getPrenom()} {$user->getNom()} (#{$user->getId()})",
            $withExtract ? 'Extraction OCR : OUI' : 'Extraction OCR : NON',
        ]);

        // 4. Scan directory for image files
        $files = $this->scanDirectory($directory);
        if (empty($files)) {
            $io->warning("Aucun fichier image trouvé dans '{$directory}'.");
            return Command::SUCCESS;
        }

        $io->info(sprintf('%d fichier(s) trouvé(s).', count($files)));

        // 5. Ask confirmation (unless --no-interaction)
        if (!$input->getOption('no-interaction') && !$io->confirm('Continuer l\'import ?', true)) {
            $io->warning('Import annulé.');
            return Command::SUCCESS;
        }

        // 6. Process files
        $uploadDir = $this->projectDir . '/var/uploads/bon_livraison';
        $stats = ['uploaded' => 0, 'extracted' => 0, 'failed' => 0, 'skipped' => 0];
        $errors = [];

        $progressBar = new ProgressBar($output, count($files));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->setMessage('Démarrage...');
        $progressBar->start();

        foreach ($files as $filePath) {
            $filename = basename($filePath);
            $progressBar->setMessage($filename);

            try {
                // a. Validate file
                $this->validateFile($filePath);

                if ($dryRun) {
                    $stats['uploaded']++;
                    $progressBar->advance();
                    continue;
                }

                // b. Copy file to upload directory with secure name
                $securePath = $this->copyToUploadDir($filePath, $uploadDir);

                // c. Strip EXIF data for JPEG
                $this->stripExifData($uploadDir . '/' . $securePath);

                // d. Create BonLivraison entity
                $bl = new BonLivraison();
                $bl->setEtablissement($etablissement);
                $bl->setStatut(StatutBonLivraison::BROUILLON);
                $bl->setImagePath($securePath);
                $bl->setCreatedBy($user);
                $bl->setDateLivraison(new \DateTimeImmutable());
                $bl->setNotes("Import batch depuis {$directory}");

                $this->entityManager->persist($bl);
                $this->entityManager->flush();
                $stats['uploaded']++;

                $this->logger->info('BL importé en batch', [
                    'bl_id' => $bl->getId(),
                    'filename' => $filename,
                    'image_path' => $securePath,
                ]);

                // e. OCR extraction if requested
                if ($withExtract) {
                    $progressBar->setMessage("{$filename} (extraction OCR...)");
                    $result = $this->extractorService->extract($bl);

                    if ($result->success) {
                        $stats['extracted']++;
                        $this->logger->info('Extraction OCR réussie', [
                            'bl_id' => $bl->getId(),
                            'lignes' => count($result->lignes),
                            'confiance' => $result->confiance,
                        ]);
                    } else {
                        $errors[] = "{$filename}: extraction échouée — " . implode(', ', $result->warnings);
                        $this->logger->warning('Extraction OCR échouée', [
                            'bl_id' => $bl->getId(),
                            'warnings' => $result->warnings,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                $errors[] = "{$filename}: {$e->getMessage()}";
                $this->logger->error('Erreur import batch BL', [
                    'filename' => $filename,
                    'error' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Terminé');
        $progressBar->finish();
        $io->newLine(2);

        // 7. Summary
        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $io->section('Résumé');

        $summaryRows = [
            ['Fichiers traités', (string) count($files)],
            ['Uploadés', (string) $stats['uploaded']],
        ];
        if ($withExtract) {
            $summaryRows[] = ['Extractions OCR réussies', (string) $stats['extracted']];
        }
        $summaryRows[] = ['Échoués', (string) $stats['failed']];

        $io->table(['', $prefix . 'Valeur'], $summaryRows);

        if (!empty($errors)) {
            $io->section('Erreurs');
            foreach ($errors as $error) {
                $io->writeln("  - {$error}");
            }
        }

        if ($stats['failed'] === 0) {
            $io->success($prefix . "Import terminé : {$stats['uploaded']} BL créé(s).");
        } else {
            $io->warning($prefix . "Import terminé avec {$stats['failed']} erreur(s).");
        }

        return $stats['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return string[] List of absolute file paths
     */
    private function scanDirectory(string $directory): array
    {
        $files = [];
        $iterator = new \DirectoryIterator($directory);

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDot() || $fileInfo->isDir()) {
                continue;
            }

            // Skip hidden files
            if (str_starts_with($fileInfo->getFilename(), '.')) {
                continue;
            }

            $extension = strtolower($fileInfo->getExtension());
            if (in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                $files[] = $fileInfo->getRealPath();
            }
        }

        sort($files);

        return $files;
    }

    private function validateFile(string $filePath): void
    {
        // Size check
        $size = filesize($filePath);
        if ($size === false || $size > self::MAX_FILE_SIZE) {
            throw new \RuntimeException('Fichier trop volumineux (max 20 Mo).');
        }

        if ($size === 0) {
            throw new \RuntimeException('Fichier vide.');
        }

        // MIME type check
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);
        if ($mimeType === false || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \RuntimeException("Type MIME non autorisé : {$mimeType}");
        }

        // Magic bytes check
        $this->validateMagicBytes($filePath, $mimeType);

        // Suspicious content check
        $this->checkForSuspiciousContent($filePath);
    }

    private function validateMagicBytes(string $filePath, string $mimeType): void
    {
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Impossible de lire le fichier.');
        }

        $header = fread($handle, 12);
        fclose($handle);

        if ($header === false) {
            throw new \RuntimeException('Impossible de lire les en-têtes du fichier.');
        }

        $expectedMagicBytes = self::MAGIC_BYTES[$mimeType] ?? [];
        $valid = false;

        foreach ($expectedMagicBytes as $magic) {
            if (str_starts_with($header, $magic)) {
                $valid = true;
                break;
            }
        }

        if (!$valid && !empty($expectedMagicBytes)) {
            throw new \RuntimeException('Magic bytes invalides — le fichier ne correspond pas au format annoncé.');
        }
    }

    private function checkForSuspiciousContent(string $filePath): void
    {
        $content = file_get_contents($filePath, false, null, 0, 8192);
        if ($content === false) {
            throw new \RuntimeException('Impossible de lire le contenu du fichier.');
        }

        $suspiciousPatterns = ['<?php', '<?=', '<script', '<%', '#!/', 'eval(', 'base64_decode(', 'system(', 'exec(', 'shell_exec(', 'passthru('];
        $contentLower = strtolower($content);

        foreach ($suspiciousPatterns as $pattern) {
            if (str_contains($contentLower, strtolower($pattern))) {
                throw new \RuntimeException('Contenu suspect détecté dans le fichier.');
            }
        }
    }

    private function copyToUploadDir(string $sourcePath, string $uploadDir): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($sourcePath);
        $extension = self::EXTENSION_MAP[$mimeType] ?? 'bin';

        $uuid = Uuid::v4()->toRfc4122();
        $datePrefix = date('Y/m');
        $relativePath = $datePrefix . '/' . $uuid . '.' . $extension;
        $targetDir = $uploadDir . '/' . $datePrefix;

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $targetPath = $uploadDir . '/' . $relativePath;
        if (!copy($sourcePath, $targetPath)) {
            throw new \RuntimeException("Impossible de copier le fichier vers {$targetPath}");
        }

        return $relativePath;
    }

    private function stripExifData(string $filePath): void
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);

        if ($mimeType !== 'image/jpeg') {
            return;
        }

        if (!extension_loaded('gd')) {
            return;
        }

        try {
            $image = imagecreatefromjpeg($filePath);
            if ($image === false) {
                return;
            }

            imagejpeg($image, $filePath, 95);
            imagedestroy($image);
        } catch (\Throwable) {
            // Non-blocking: EXIF cleanup failure is not critical
        }
    }

    private function resolveUser(InputInterface $input, SymfonyStyle $io, mixed $etablissement): ?\App\Entity\Utilisateur
    {
        $userId = $input->getOption('user');

        if ($userId !== null) {
            $user = $this->utilisateurRepository->find((int) $userId);
            if ($user === null) {
                $io->error("Utilisateur #{$userId} introuvable.");
                return null;
            }
            return $user;
        }

        // Default: find first ADMIN of the organisation
        $organisation = $etablissement->getOrganisation();
        if ($organisation === null) {
            $io->error("L'établissement n'a pas d'organisation associée.");
            return null;
        }

        $users = $this->utilisateurRepository->findBy(['organisation' => $organisation, 'actif' => true]);
        foreach ($users as $user) {
            if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                return $user;
            }
        }

        $io->error("Aucun administrateur actif trouvé pour l'organisation. Utilisez --user pour spécifier un utilisateur.");
        return null;
    }
}
