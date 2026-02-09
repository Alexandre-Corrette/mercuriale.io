<?php

declare(strict_types=1);

namespace App\Exception;

class InvalidFileException extends \RuntimeException
{
    public const INVALID_MIME_TYPE = 'INVALID_MIME_TYPE';
    public const FILE_TOO_LARGE = 'FILE_TOO_LARGE';
    public const INVALID_MAGIC_BYTES = 'INVALID_MAGIC_BYTES';
    public const UPLOAD_ERROR = 'UPLOAD_ERROR';
    public const SUSPICIOUS_FILE = 'SUSPICIOUS_FILE';
    public const CONVERSION_ERROR = 'CONVERSION_ERROR';
    public const FILE_NOT_READABLE = 'FILE_NOT_READABLE';

    private string $errorType;

    public function __construct(string $errorType, string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $this->errorType = $errorType;

        if (empty($message)) {
            $message = $this->getDefaultMessage($errorType);
        }

        parent::__construct($message, $code, $previous);
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }

    private function getDefaultMessage(string $errorType): string
    {
        return match ($errorType) {
            self::INVALID_MIME_TYPE => 'Le type de fichier n\'est pas autorisé. Formats acceptés : JPEG, PNG, HEIC, PDF.',
            self::FILE_TOO_LARGE => 'Le fichier est trop volumineux. Taille maximale : 20 Mo.',
            self::INVALID_MAGIC_BYTES => 'Le fichier ne correspond pas au format annoncé.',
            self::UPLOAD_ERROR => 'Une erreur est survenue lors de l\'upload du fichier.',
            self::SUSPICIOUS_FILE => 'Le fichier contient du contenu suspect et a été rejeté.',
            self::CONVERSION_ERROR => 'Erreur lors de la conversion du fichier.',
            self::FILE_NOT_READABLE => 'Le fichier ne peut pas être lu.',
            default => 'Erreur de fichier inconnue.',
        };
    }
}
