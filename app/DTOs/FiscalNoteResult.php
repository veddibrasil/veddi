<?php

namespace App\DTOs;

readonly class FiscalNoteResult
{
    public function __construct(
        public string $status,
        public ?string $providerReference = null,
        public ?string $accessKey = null,
        public ?string $xmlUrl = null,
        public ?string $danfeUrl = null,
        public ?string $errorMessage = null,
        public array $rawResponse = [],
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status === 'authorized';
    }
}
