<?php

namespace App\Contracts;

use App\DTOs\FiscalNoteDTO;
use App\DTOs\FiscalNoteResult;

interface FiscalNoteProviderInterface
{
    public function issue(FiscalNoteDTO $dto): FiscalNoteResult;

    public function cancel(string $providerReference, string $justification): FiscalNoteResult;

    public function query(string $accessKey): FiscalNoteResult;
}
