<?php

declare(strict_types=1);

namespace App\Service\Ocr;

class ImageCompressor
{
    // ~3.5 MB en binaire → ~4.7 MB en base64, sous la limite API de 5 MB
    private const MAX_BASE64_BYTES = 3_500_000;
    private const MAX_DIMENSION = 2048;
    private const INITIAL_QUALITY = 90;
    private const MIN_QUALITY = 60;
    private const QUALITY_STEP = 5;
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

        // Fast-path : JPEG sous la limite ET sans rotation EXIF à appliquer
        $rawBase64Size = (int) ceil($originalSize * 4 / 3);
        $exifOrientation = $this->readExifOrientation($imagePath, $mimeType);
        if ($rawBase64Size <= self::MAX_BASE64_BYTES && $mimeType === 'image/jpeg' && $exifOrientation === 1) {
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
        $image = $this->applyExifOrientation($image, $imagePath, $mimeType);
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

    private function readExifOrientation(string $path, string $mimeType): int
    {
        if ($mimeType !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path);
        if ($exif === false || !isset($exif['Orientation'])) {
            return 1;
        }

        $orientation = (int) $exif['Orientation'];
        return ($orientation >= 1 && $orientation <= 8) ? $orientation : 1;
    }

    private function applyExifOrientation(\GdImage $image, string $path, string $mimeType): \GdImage
    {
        $orientation = $this->readExifOrientation($path, $mimeType);
        if ($orientation === 1) {
            return $image;
        }

        // EXIF orientation: 1=normal, 2=flip-h, 3=180°, 4=flip-v, 5=transpose,
        // 6=90°CW, 7=transverse, 8=90°CCW (= 270°CW)
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            2, 4, 5, 7 => imagerotate($image, match ($orientation) {
                4 => 180,
                5 => -90,
                7 => 90,
                default => 0,
            }, 0),
            default => $image,
        };

        if ($rotated === false) {
            return $image;
        }

        if ($rotated !== $image) {
            imagedestroy($image);
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
        }

        return $rotated;
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

    /**
     * Preprocessing OCR : greyscale + contrast + sharpen pour les BL cuisine.
     */
    private function enhanceForOcr(\GdImage $image): \GdImage
    {
        // Greyscale — supprime le bruit couleur (taches, graisse, encre)
        imagefilter($image, IMG_FILTER_GRAYSCALE);

        // Contraste — renforce texte vs fond (+20, négatif = plus de contraste en GD)
        imagefilter($image, IMG_FILTER_CONTRAST, -20);

        // Luminosité — compense zones sombres (plis, ombres)
        imagefilter($image, IMG_FILTER_BRIGHTNESS, 10);

        // Netteté — via unsharp mask (matrice de convolution)
        $sharpen = [
            [-1, -1, -1],
            [-1, 16, -1],
            [-1, -1, -1],
        ];
        $divisor = array_sum(array_map('array_sum', $sharpen));
        imageconvolution($image, $sharpen, $divisor, 0);

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
