<?php

declare(strict_types=1);

namespace App\Service\Upload;

use App\DTO\UploadResult;
use App\Entity\BonLivraison;
use App\Entity\Etablissement;
use App\Entity\Utilisateur;
use App\Enum\StatutBonLivraison;
use App\Exception\InvalidFileException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

class BonLivraisonUploadService
{
    private const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20 Mo

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/heic',
        'image/heif',
        'application/pdf',
    ];

    private const MAGIC_BYTES = [
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png' => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
        'image/heic' => ["ftypheic", "ftypmif1", "ftypmsf1", "ftypheix"],
        'image/heif' => ["ftypheic", "ftypmif1", "ftypmsf1", "ftypheix"],
        'application/pdf' => ["%PDF"],
    ];

    private const EXTENSION_MAP = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/heic' => 'jpg', // Sera converti en JPEG
        'image/heif' => 'jpg', // Sera converti en JPEG
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Upload un fichier unique (méthode conservée pour rétrocompatibilité).
     */
    public function upload(UploadedFile $file, Etablissement $etablissement, Utilisateur $user): BonLivraison
    {
        // Valider le fichier
        $this->validateFile($file);

        $bonLivraison = $this->uploadSingle($file, $etablissement, $user, true);

        $this->logger->info('BL créé avec succès', [
            'bl_id' => $bonLivraison->getId(),
            'filename' => $bonLivraison->getImagePath(),
            'etablissement_id' => $etablissement->getId(),
            'user_id' => $user->getId(),
        ]);

        return $bonLivraison;
    }

    /**
     * Upload multiple fichiers avec gestion des erreurs partielles.
     *
     * Stratégie : upload atomique par fichier
     * - Chaque fichier est traité indépendamment
     * - Les fichiers valides sont sauvegardés même si d'autres échouent
     * - Un rapport détaillé des succès/échecs est retourné
     *
     * @param array<int, UploadedFile> $files
     */
    public function uploadMultiple(array $files, Etablissement $etablissement, Utilisateur $user): UploadResult
    {
        $successfulUploads = [];
        $failedUploads = [];

        // Pré-validation rapide de tous les fichiers (sans écriture)
        $validatedFiles = $this->preValidateFiles($files);

        foreach ($files as $index => $file) {
            $filename = $file->getClientOriginalName();

            // Vérifier si le fichier a échoué la pré-validation
            if (isset($validatedFiles['errors'][$index])) {
                $failedUploads[] = [
                    'filename' => $filename,
                    'error' => $validatedFiles['errors'][$index],
                ];
                continue;
            }

            try {
                $bonLivraison = $this->uploadSingle($file, $etablissement, $user, false);
                $successfulUploads[] = $bonLivraison;

                $this->logger->info('Fichier uploadé avec succès', [
                    'filename' => $filename,
                    'bl_id' => $bonLivraison->getId(),
                    'index' => $index + 1,
                    'total' => count($files),
                ]);
            } catch (InvalidFileException $e) {
                $failedUploads[] = [
                    'filename' => $filename,
                    'error' => $e->getMessage(),
                ];

                $this->logger->warning('Échec upload fichier', [
                    'filename' => $filename,
                    'error_type' => $e->getErrorType(),
                    'error' => $e->getMessage(),
                    'index' => $index + 1,
                ]);
            } catch (\Throwable $e) {
                $failedUploads[] = [
                    'filename' => $filename,
                    'error' => 'Erreur inattendue lors du traitement du fichier.',
                ];

                $this->logger->error('Erreur inattendue upload', [
                    'filename' => $filename,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Flush tous les BL en une seule transaction
        if (!empty($successfulUploads)) {
            $this->entityManager->flush();
        }

        $result = new UploadResult($successfulUploads, $failedUploads);

        $this->logger->info('Upload multiple terminé', [
            'total' => $result->getTotalCount(),
            'success' => $result->getSuccessCount(),
            'failures' => $result->getFailureCount(),
            'etablissement_id' => $etablissement->getId(),
            'user_id' => $user->getId(),
        ]);

        return $result;
    }

    /**
     * Pré-validation rapide sans écriture sur disque.
     *
     * @param array<int, UploadedFile> $files
     * @return array{valid: array<int, true>, errors: array<int, string>}
     */
    private function preValidateFiles(array $files): array
    {
        $result = ['valid' => [], 'errors' => []];

        foreach ($files as $index => $file) {
            try {
                // Vérifications rapides sans écriture
                if (!$file->isValid()) {
                    throw new InvalidFileException(
                        InvalidFileException::UPLOAD_ERROR,
                        'Erreur d\'upload : ' . $file->getErrorMessage()
                    );
                }

                if ($file->getSize() > self::MAX_FILE_SIZE) {
                    throw new InvalidFileException(InvalidFileException::FILE_TOO_LARGE);
                }

                $this->validateMimeType($file);
                $this->validateMagicBytes($file->getPathname());
                $this->checkForSuspiciousContent($file->getPathname());

                $result['valid'][$index] = true;
            } catch (InvalidFileException $e) {
                $result['errors'][$index] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Upload un seul fichier avec option de flush différé.
     */
    private function uploadSingle(
        UploadedFile $file,
        Etablissement $etablissement,
        Utilisateur $user,
        bool $flushImmediately = true
    ): BonLivraison {
        // Générer un nom de fichier sécurisé
        $secureFilename = $this->generateSecureFilename($file);

        // Créer le répertoire si nécessaire
        $uploadDir = $this->getUploadDirectory();
        $dateDir = dirname($secureFilename);
        $fullUploadDir = $uploadDir . '/' . $dateDir;

        if (!is_dir($fullUploadDir)) {
            mkdir($fullUploadDir, 0755, true);
        }

        // Déplacer le fichier
        $targetPath = $uploadDir . '/' . $secureFilename;
        $file->move($fullUploadDir, basename($secureFilename));

        // Nettoyer les métadonnées EXIF
        $this->stripExifData($targetPath);

        // Convertir HEIC en JPEG si nécessaire
        $mimeType = $this->detectMimeType($targetPath);
        if (in_array($mimeType, ['image/heic', 'image/heif'], true)) {
            $secureFilename = $this->convertHeicToJpeg($targetPath);
        }

        // Créer l'entité BonLivraison
        $bonLivraison = new BonLivraison();
        $bonLivraison->setEtablissement($etablissement);
        $bonLivraison->setStatut(StatutBonLivraison::BROUILLON);
        $bonLivraison->setImagePath($secureFilename);
        $bonLivraison->setCreatedBy($user);
        $bonLivraison->setDateLivraison(new \DateTimeImmutable());

        $this->entityManager->persist($bonLivraison);

        if ($flushImmediately) {
            $this->entityManager->flush();
        }

        return $bonLivraison;
    }

    public function validateFile(UploadedFile $file): void
    {
        // Vérifier les erreurs d'upload
        if (!$file->isValid()) {
            throw new InvalidFileException(
                InvalidFileException::UPLOAD_ERROR,
                'Erreur d\'upload : ' . $file->getErrorMessage()
            );
        }

        // Vérifier la taille
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new InvalidFileException(InvalidFileException::FILE_TOO_LARGE);
        }

        // Valider le MIME type
        $this->validateMimeType($file);

        // Valider les magic bytes
        $this->validateMagicBytes($file->getPathname());

        // Vérifier que ce n'est pas un script déguisé
        $this->checkForSuspiciousContent($file->getPathname());
    }

    public function validateMimeType(UploadedFile $file): void
    {
        // Utiliser finfo pour détecter le vrai MIME type (pas celui envoyé par le client)
        $detectedMime = $this->detectMimeType($file->getPathname());

        if (!in_array($detectedMime, self::ALLOWED_MIME_TYPES, true)) {
            $this->logger->warning('MIME type invalide détecté', [
                'detected' => $detectedMime,
                'client_mime' => $file->getMimeType(),
                'filename' => $file->getClientOriginalName(),
            ]);
            throw new InvalidFileException(InvalidFileException::INVALID_MIME_TYPE);
        }
    }

    public function validateMagicBytes(string $filePath): void
    {
        if (!is_readable($filePath)) {
            throw new InvalidFileException(InvalidFileException::FILE_NOT_READABLE);
        }

        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            throw new InvalidFileException(InvalidFileException::FILE_NOT_READABLE);
        }

        // Lire les premiers octets pour vérifier les magic bytes
        $header = fread($handle, 12);
        fclose($handle);

        if ($header === false) {
            throw new InvalidFileException(InvalidFileException::FILE_NOT_READABLE);
        }

        $mimeType = $this->detectMimeType($filePath);
        $expectedMagicBytes = self::MAGIC_BYTES[$mimeType] ?? [];

        $valid = false;
        foreach ($expectedMagicBytes as $magic) {
            // Pour HEIC, les magic bytes sont à l'offset 4
            if (in_array($mimeType, ['image/heic', 'image/heif'], true)) {
                if (str_contains($header, $magic)) {
                    $valid = true;
                    break;
                }
            } else {
                if (str_starts_with($header, $magic)) {
                    $valid = true;
                    break;
                }
            }
        }

        if (!$valid && !empty($expectedMagicBytes)) {
            $this->logger->warning('Magic bytes invalides', [
                'mime' => $mimeType,
                'header_hex' => bin2hex(substr($header, 0, 8)),
            ]);
            throw new InvalidFileException(InvalidFileException::INVALID_MAGIC_BYTES);
        }
    }

    public function generateSecureFilename(UploadedFile $file): string
    {
        $mimeType = $this->detectMimeType($file->getPathname());
        $extension = self::EXTENSION_MAP[$mimeType] ?? 'bin';

        // UUID v4 pour le nom de fichier
        $uuid = Uuid::v4()->toRfc4122();

        // Organiser par date pour éviter trop de fichiers dans un même dossier
        $datePrefix = date('Y/m');

        return $datePrefix . '/' . $uuid . '.' . $extension;
    }

    public function stripExifData(string $filePath): void
    {
        $mimeType = $this->detectMimeType($filePath);

        // Seules les images JPEG contiennent des EXIF significatifs
        if ($mimeType !== 'image/jpeg') {
            return;
        }

        if (!extension_loaded('gd')) {
            $this->logger->warning('Extension GD non disponible, impossible de nettoyer les EXIF');
            return;
        }

        try {
            $image = imagecreatefromjpeg($filePath);
            if ($image === false) {
                return;
            }

            // Réécrire l'image sans les métadonnées EXIF
            imagejpeg($image, $filePath, 95);
            imagedestroy($image);

            $this->logger->debug('EXIF nettoyé', ['file' => basename($filePath)]);
        } catch (\Throwable $e) {
            $this->logger->warning('Erreur lors du nettoyage EXIF', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function convertHeicToJpeg(string $filePath): string
    {
        if (!extension_loaded('imagick')) {
            throw new InvalidFileException(
                InvalidFileException::CONVERSION_ERROR,
                'Extension Imagick non disponible pour convertir les fichiers HEIC.'
            );
        }

        try {
            $imagick = new \Imagick($filePath);
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(90);

            // Nouveau nom de fichier avec extension .jpg
            $newFilePath = preg_replace('/\.(heic|heif)$/i', '.jpg', $filePath);
            if ($newFilePath === $filePath) {
                $newFilePath = $filePath . '.jpg';
            }

            $imagick->writeImage($newFilePath);
            $imagick->destroy();

            // Supprimer l'ancien fichier HEIC
            if (file_exists($filePath) && $filePath !== $newFilePath) {
                unlink($filePath);
            }

            $this->logger->info('HEIC converti en JPEG', [
                'original' => basename($filePath),
                'converted' => basename($newFilePath),
            ]);

            return basename(dirname($newFilePath)) . '/' . basename($newFilePath);
        } catch (\ImagickException $e) {
            throw new InvalidFileException(
                InvalidFileException::CONVERSION_ERROR,
                'Erreur lors de la conversion HEIC : ' . $e->getMessage()
            );
        }
    }

    public function getUploadDirectory(): string
    {
        return $this->projectDir . '/var/uploads/bon_livraison';
    }

    private function detectMimeType(string $filePath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);

        // finfo ne reconnaît pas toujours HEIC, vérifier manuellement
        if ($mimeType === 'application/octet-stream' || $mimeType === false) {
            $handle = fopen($filePath, 'rb');
            if ($handle) {
                $header = fread($handle, 12);
                fclose($handle);

                if ($header && str_contains($header, 'ftyp')) {
                    if (str_contains($header, 'heic') || str_contains($header, 'heix') || str_contains($header, 'mif1')) {
                        return 'image/heic';
                    }
                }
            }
        }

        return $mimeType ?: 'application/octet-stream';
    }

    private function checkForSuspiciousContent(string $filePath): void
    {
        $content = file_get_contents($filePath, false, null, 0, 8192);

        if ($content === false) {
            throw new InvalidFileException(InvalidFileException::FILE_NOT_READABLE);
        }

        // Patterns PHP et autres scripts dangereux
        $suspiciousPatterns = [
            '<?php',
            '<?=',
            '<script',
            '<%',
            '#!/',
            'eval(',
            'base64_decode(',
            'system(',
            'exec(',
            'shell_exec(',
            'passthru(',
        ];

        $contentLower = strtolower($content);
        foreach ($suspiciousPatterns as $pattern) {
            if (str_contains($contentLower, strtolower($pattern))) {
                $this->logger->alert('Contenu suspect détecté dans un fichier uploadé', [
                    'pattern' => $pattern,
                    'file' => basename($filePath),
                ]);
                throw new InvalidFileException(InvalidFileException::SUSPICIOUS_FILE);
            }
        }
    }
}
