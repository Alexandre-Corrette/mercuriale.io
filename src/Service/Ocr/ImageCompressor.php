<?php

declare(strict_types=1);

namespace App\Service\Ocr;

class ImageCompressor
{
    // ~3.5 MB en binaire → ~4.7 MB en base64, sous la limite API de 5 MB
    private const MAX_BASE64_BYTES = 3_500_000;
    private const MAX_DIMENSION = 2048;
    private const INITIAL_QUALITY = 85;
    private const MIN_QUALITY = 30;
    private const QUALITY_STEP = 10;
    // 48 MP décompressée ~= 180 MB, limiter à 50 MP (sécurité mémoire GD)
    private const MAX_PIXELS = 50_000_000;

    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Compresse l'image si nécessaire pour respecter la limite API.
     *
     * @param string $imagePath Chemin absolu vers l'image uploadée
     *
     * @return array{base64: string, mediaType: string, originalSize: int, finalSize: int, wasCompressed: bool}
     *
     * @throws \InvalidArgumentException Si le fichier n'est pas une image valide
     * @throws \RuntimeException         Si la compression échoue
     */
    public function prepareForApi(string $imagePath): array
    {
        $this->validateImage($imagePath);

        $originalSize = filesize($imagePath);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($imagePath);

        // Vérifier si la compression est nécessaire
        $rawBase64Size = (int) ceil($originalSize * 4 / 3);
        if ($rawBase64Size <= self::MAX_BASE64_BYTES && $mimeType === 'image/jpeg') {
            // Déjà sous la limite et en JPEG : envoyer tel quel
            $imageContent = file_get_contents($imagePath);
            if ($imageContent === false) {
                throw new \RuntimeException('Impossible de lire le fichier image');
            }

            return [
                'base64' => base64_encode($imageContent),
                'mediaType' => 'image/jpeg',
                'originalSize' => $originalSize,
                'finalSize' => $originalSize,
                'wasCompressed' => false,
            ];
        }

        $image = $this->loadImage($imagePath, $mimeType);
        $image = $this->resizeIfNeeded($image);

        // Boucle de compression qualité dégressive
        $quality = self::INITIAL_QUALITY;
        $jpegData = null;
        $base64Size = 0;

        do {
            ob_start();
            imagejpeg($image, null, $quality);
            $jpegData = ob_get_clean();

            $base64Size = (int) ceil(strlen($jpegData) * 4 / 3);

            if ($base64Size <= self::MAX_BASE64_BYTES) {
                break;
            }

            $quality -= self::QUALITY_STEP;
        } while ($quality >= self::MIN_QUALITY);

        imagedestroy($image);

        if ($base64Size > self::MAX_BASE64_BYTES) {
            throw new \RuntimeException(sprintf(
                'Impossible de compresser l\'image sous la limite API (%.1f MB après compression à qualité %d)',
                $base64Size / 1_048_576,
                $quality + self::QUALITY_STEP
            ));
        }

        return [
            'base64' => base64_encode($jpegData),
            'mediaType' => 'image/jpeg',
            'originalSize' => $originalSize,
            'finalSize' => strlen($jpegData),
            'wasCompressed' => true,
        ];
    }

    private function validateImage(string $imagePath): void
    {
        if (!file_exists($imagePath) || !is_readable($imagePath)) {
            throw new \InvalidArgumentException("Fichier image introuvable ou illisible : {$imagePath}");
        }

        // Double validation : getimagesize (decode header) + finfo (magic bytes)
        $imageInfo = getimagesize($imagePath);
        if ($imageInfo === false) {
            throw new \InvalidArgumentException('Fichier non reconnu comme image valide');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($imagePath);
        if (!in_array($mimeType, self::ALLOWED_MIMES, true)) {
            throw new \InvalidArgumentException(sprintf('Type MIME non supporté : %s', $mimeType));
        }

        // Vérifier les dimensions pour éviter les OOM GD
        $pixels = $imageInfo[0] * $imageInfo[1];
        if ($pixels > self::MAX_PIXELS) {
            throw new \InvalidArgumentException(sprintf(
                'Image trop grande (%dx%d = %d Mpx, max %d Mpx)',
                $imageInfo[0],
                $imageInfo[1],
                (int) ($pixels / 1_000_000),
                (int) (self::MAX_PIXELS / 1_000_000)
            ));
        }
    }

    private function loadImage(string $path, string $mimeType): \GdImage
    {
        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => throw new \InvalidArgumentException("Type non supporté : {$mimeType}"),
        };

        if ($image === false) {
            throw new \RuntimeException('Impossible de charger l\'image avec GD');
        }

        return $image;
    }

    private function resizeIfNeeded(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width <= self::MAX_DIMENSION && $height <= self::MAX_DIMENSION) {
            return $image;
        }

        $ratio = min(self::MAX_DIMENSION / $width, self::MAX_DIMENSION / $height);
        $newWidth = (int) round($width * $ratio);
        $newHeight = (int) round($height * $ratio);

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }
}
