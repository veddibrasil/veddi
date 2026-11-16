<?php

namespace App\DTOs;

/**
 * Resultado da FiscalEmissionPolicy para uma venda: modelo a emitir, modelos que o operador pode
 * escolher e por quê. model = null quando falta informação para decidir (needs) ou nenhum modelo
 * suportado atende a operação (reasons) — a emissão fica bloqueada até alguém resolver.
 */
readonly class FiscalEmissionDecision
{
    /**
     * @param  array<int, string>  $allowedModels  '65' NFC-e, '55' NF-e
     * @param  array<int, string>  $reasons  explicação para a tela (por que este modelo ou o bloqueio)
     * @param  array<int, string>  $needs  informação que falta: 'document', 'ie_confirmation'
     */
    public function __construct(
        public ?string $model,
        public array $allowedModels,
        public array $reasons,
        public array $needs,
        public string $documentType,
        public bool $interstate,
        public bool $presential,
        public string $version,
    ) {}

    public function blocked(): bool
    {
        return $this->model === null;
    }

    public function allows(string $model): bool
    {
        return in_array($model, $this->allowedModels, true);
    }

    /** Operador tem escolha real entre NFC-e e NF-e. */
    public function hasChoice(): bool
    {
        return count($this->allowedModels) > 1;
    }

    public static function label(?string $model): string
    {
        return match ($model) {
            '55' => 'NF-e',
            '65' => 'NFC-e',
            default => 'nota fiscal',
        };
    }

    /** Gravado na nota antes do envio — permite auditar com qual versão da política ela saiu. */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'model' => $this->model,
            'allowed_models' => $this->allowedModels,
            'document_type' => $this->documentType,
            'presential' => $this->presential,
            'interstate' => $this->interstate,
            'reasons' => $this->reasons,
        ];
    }
}
