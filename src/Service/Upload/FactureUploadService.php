<?php

declare(strict_types=1);

namespace App\Service\Upload;

use App\Entity\Etablissement;
use App\Entity\FactureFournisseur;
use App\Entity\Fournisseur;
use App\Entity\Utilisateur;
use App\Enum\SourceFacture;
use App\Enum\StatutFacture;
use App\Exception\InvalidFileException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

class FactureUploadService
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
        'image/heic' => 'jpg',
        'image/heif' => 'jpg',
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Upload a single invoice file, create a BROUILLON FactureFournisseur, and return it.
     * The entity is persisted and flushed.
     */
    public function upload(
        UploadedFile $file,
        Etablissement $etablissement,
        Utilisateur $user,
        ?Fournisseur $fournisseur = null,
    ): FactureFournisseur {
        $this->validateFile($file);

        $originalName = $file->getClientOriginalName();
        $secureFilename = $this->generateSecureFilename($file);

        // Create directory and move file
        $uploadDir = $this->getUploadDirectory();
        $dateDir = dirname($secureFilename);
        $fullUploadDir = $uploadDir . '/' . $dateDir;

        if (!is_dir($fullUploadDir)) {
            mkdir($fullUploadDir, 0750, true);
        }

        $targetPath = $uploadDir . '/' . $secureFilename;
        $file->move($fullUploadDir, basename($secureFilename));

        // chmod 640 on the stored file
        chmod($targetPath, 0640);

        // Strip EXIF metadata from JPEG images
        $this->stripExifData($targetPath);

        // Convert HEIC to JPEG if necessary
        $mimeType = $this->detectMimeType($targetPath);
        if (in_array($mimeType, ['image/heic', 'image/heif'], true)) {
            $secureFilename = $this->convertHeicToJpeg($targetPath);
        }

        // Create the FactureFournisseur entity in BROUILLON state
        $facture = new FactureFournisseur();
        $facture->setSource(SourceFacture::UPLOAD_OCR);
        $facture->setStatut(StatutFacture::BROUILLON);
        $facture->setEtablissement($etablissement);
        $facture->setDocumentOriginalPath($secureFilename);
        $facture->setFichierOriginalNom($originalName);
        $facture->setCreatedBy($user);

        if ($fournisseur !== null) {
            $facture->setFournisseur($fournisseur);
            $facture->setFournisseurNom($fournisseur->getNom());
        }

        $this->entityManager->persist($facture);
        $this->entityManager->flush();

        $this->logger->info('Facture uploadée avec succès', [
            'facture_id' => $facture->getIdAsString(),
            'filename' => $originalName,
            'stored_as' => $secureFilename,
            'etablissement_id' => $etablissement->getId(),
            'user_id' => $user->getId(),
        ]);

        return $facture;
    }

    public function validateFile(UploadedFile $file): void
    {
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
    }

    public function getUploadDirectory(): string
    {
        return $this->projectDir . '/var/factures';
    }

    private function validateMimeType(UploadedFile $file): void
    {
        $detectedMime = $this->detectMimeType($file->getPathname());

        if (!in_array($detectedMime, self::ALLOWED_MIME_TYPES, true)) {
            $this->logger->warning('MIME type invalide détecté pour facture', [
                'detected' => $detectedMime,
                'client_mime' => $file->getMimeType(),
                'filename' => $file->getClientOriginalName(),
            ]);
            throw new InvalidFileException(InvalidFileException::INVALID_MIME_TYPE);
        }
    }

    private function validateMagicBytes(string $filePath): void
    {
        if (!is_readable($filePath)) {
            throw new InvalidFileException(InvalidFileException::FILE_NOT_READABLE);
        }

        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            throw new InvalidFileException(InvalidFileException::FILE_NOT_READABLE);
        }

        $header = fread($handle, 12);
        fclose($handle);

        if ($header === false) {
            throw new InvalidFileException(InvalidFileException::FILE_NOT_READABLE);
        }

        $mimeType = $this->detectMimeType($filePath);
        $expectedMagicBytes = self::MAGIC_BYTES[$mimeType] ?? [];

        $valid = false;
        foreach ($expectedMagicBytes as $magic) {
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
            $this->logger->warning('Magic bytes invalides pour facture', [
                'mime' => $mimeType,
                'header_hex' => bin2hex(substr($header, 0, 8)),
            ]);
            throw new InvalidFileException(InvalidFileException::INVALID_MAGIC_BYTES);
        }
    }

    private function generateSecureFilename(UploadedFile $file): string
    {
        $mimeType = $this->detectMimeType($file->getPathname());
        $extension = self::EXTENSION_MAP[$mimeType] ?? 'bin';

        $uuid = Uuid::v4()->toRfc4122();
        $datePrefix = date('Y/m');

        return $datePrefix . '/' . $uuid . '.' . $extension;
    }

    private function stripExifData(string $filePath): void
    {
        $mimeType = $this->detectMimeType($filePath);

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

            imagejpeg($image, $filePath, 95);
            imagedestroy($image);
        } catch (\Throwable $e) {
            $this->logger->warning('Erreur lors du nettoyage EXIF facture', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function convertHeicToJpeg(string $filePath): string
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

            $newFilePath = preg_replace('/\.(heic|heif)$/i', '.jpg', $filePath);
            if ($newFilePath === $filePath) {
                $newFilePath = $filePath . '.jpg';
            }

            $imagick->writeImage($newFilePath);
            $imagick->destroy();

            if (file_exists($filePath) && $filePath !== $newFilePath) {
                unlink($filePath);
            }

            return basename(dirname($newFilePath)) . '/' . basename($newFilePath);
        } catch (\ImagickException $e) {
            throw new InvalidFileException(
                InvalidFileException::CONVERSION_ERROR,
                'Erreur lors de la conversion HEIC : ' . $e->getMessage()
            );
        }
    }

    private function detectMimeType(string $filePath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);

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
                $this->logger->alert('Contenu suspect détecté dans une facture uploadée', [
                    'pattern' => $pattern,
                    'file' => basename($filePath),
                ]);
                throw new InvalidFileException(InvalidFileException::SUSPICIOUS_FILE);
            }
        }
    }
}
