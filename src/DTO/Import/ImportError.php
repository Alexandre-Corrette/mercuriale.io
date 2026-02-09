<?php

declare(strict_types=1);

namespace App\DTO\Import;

final readonly class ImportError
{
    public function __construct(
        public int $row,
        public string $column,
        public string $message,
        public ?string $value = null,
    ) {}

    public function toArray(): array
    {
        return [
            'row' => $this->row,
            'column' => $this->column,
            'message' => $this->message,
            'value' => $this->value,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            row: $data['row'],
            column: $data['column'],
            message: $data['message'],
            value: $data['value'] ?? null,
        );
    }
}
