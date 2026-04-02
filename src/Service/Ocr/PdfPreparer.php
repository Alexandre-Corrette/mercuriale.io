<?php

declare(strict_types=1);

namespace App\Service\Ocr;

class PdfPreparer
{
    private const MAX_PDF_BYTES = 32_000_000; // 32 MB limite API Anthropic
    private const ALLOWED_MIMES = ['application/pdf'];

    /**
     * Prépare un PDF pour l'envoi à l'API Anthropic (type "document").
     *
     * @return array{base64: string, mediaType: string, originalSize: int, finalSize: int, wasCompressed: bool}
     */
    public function prepareForApi(string $filePath): array
    {
        $this->validate($filePath);

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException('Impossible de lire le PDF');
        }

        return [
            'base64' => base64_encode($content),
            'mediaType' => 'application/pdf',
            'originalSize' => filesize($filePath),
            'finalSize' => strlen($content),
            'wasCompressed' => false,
        ];
    }

    private function validate(string $filePath): void
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("PDF introuvable : {$filePath}");
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($filePath);

        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new \InvalidArgumentException("Type MIME non supporté : {$mime}");
        }

        if (filesize($filePath) > self::MAX_PDF_BYTES) {
            throw new \InvalidArgumentException('PDF trop volumineux (max 32 MB)');
        }
    }
}
