<?php

declare(strict_types=1);

namespace App\Exception\Import;

class ImportException extends \RuntimeException
{
    public const ERROR_FILE_TOO_LARGE = 'file_too_large';
    public const ERROR_INVALID_FORMAT = 'invalid_format';
    public const ERROR_EMPTY_FILE = 'empty_file';
    public const ERROR_TOO_MANY_ROWS = 'too_many_rows';
    public const ERROR_MALICIOUS_CONTENT = 'malicious_content';
    public const ERROR_PARSE_FAILED = 'parse_failed';
    public const ERROR_IMPORT_EXPIRED = 'import_expired';
    public const ERROR_INVALID_MAPPING = 'invalid_mapping';
    public const ERROR_MISSING_REQUIRED_COLUMNS = 'missing_required_columns';
    public const ERROR_RATE_LIMIT_EXCEEDED = 'rate_limit_exceeded';
    public const ERROR_ACCESS_DENIED = 'access_denied';
    public const ERROR_IMPORT_FAILED = 'import_failed';

    private const DEFAULT_MESSAGES = [
        self::ERROR_FILE_TOO_LARGE => 'Le fichier est trop volumineux (maximum 10 Mo)',
        self::ERROR_INVALID_FORMAT => 'Format de fichier non supporté. Utilisez un fichier CSV ou XLSX.',
        self::ERROR_EMPTY_FILE => 'Le fichier est vide ou ne contient pas de données',
        self::ERROR_TOO_MANY_ROWS => 'Le fichier contient trop de lignes (maximum 5000)',
        self::ERROR_MALICIOUS_CONTENT => 'Le fichier contient du contenu potentiellement dangereux',
        self::ERROR_PARSE_FAILED => 'Impossible de lire le fichier',
        self::ERROR_IMPORT_EXPIRED => 'L\'import a expiré. Veuillez recommencer.',
        self::ERROR_INVALID_MAPPING => 'La configuration de mapping est invalide',
        self::ERROR_MISSING_REQUIRED_COLUMNS => 'Des colonnes obligatoires ne sont pas mappées',
        self::ERROR_RATE_LIMIT_EXCEEDED => 'Trop d\'imports récents. Veuillez patienter.',
        self::ERROR_ACCESS_DENIED => 'Vous n\'avez pas accès à ce fournisseur',
        self::ERROR_IMPORT_FAILED => 'L\'import a échoué',
    ];

    public function __construct(
        private readonly string $errorType,
        ?string $message = null,
        ?\Throwable $previous = null,
    ) {
        $finalMessage = $message ?? self::DEFAULT_MESSAGES[$errorType] ?? 'Une erreur est survenue';
        parent::__construct($finalMessage, 0, $previous);
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }

    public static function fileTooLarge(int $maxSizeBytes): self
    {
        $maxSizeMb = round($maxSizeBytes / 1024 / 1024, 1);

        return new self(
            self::ERROR_FILE_TOO_LARGE,
            sprintf('Le fichier est trop volumineux (maximum %s Mo)', $maxSizeMb),
        );
    }

    public static function tooManyRows(int $maxRows, int $actualRows): self
    {
        return new self(
            self::ERROR_TOO_MANY_ROWS,
            sprintf('Le fichier contient trop de lignes (%d, maximum %d)', $actualRows, $maxRows),
        );
    }

    public static function missingColumns(array $columns): self
    {
        return new self(
            self::ERROR_MISSING_REQUIRED_COLUMNS,
            sprintf('Colonnes obligatoires manquantes : %s', implode(', ', $columns)),
        );
    }

    public static function maliciousContent(string $type, int $row): self
    {
        return new self(
            self::ERROR_MALICIOUS_CONTENT,
            sprintf('Contenu potentiellement dangereux détecté (%s) à la ligne %d', $type, $row),
        );
    }
}
