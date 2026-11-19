<?php

namespace App\DTOs;

/**
 * Resposta da FiscalAutoIssuePolicy para um pedido:
 * - off: emissão automática não vale para este pedido (filial sem a chave, módulo desligado,
 *   pedido anterior à ativação). Nada é gravado.
 * - wait: ainda não (falta aceite ou pagamento). Reavaliado na próxima mudança e pela varredura.
 * - skip: a emissão automática não emite este pedido; ele fica no Controle de notas. Gravado
 *   uma vez em orders.fiscal_auto_attempted_at para não ser reavaliado.
 * - issue: emitir NFC-e agora.
 */
final class FiscalAutoIssueVerdict
{
    public const OFF = 'off';

    public const WAIT = 'wait';

    public const SKIP = 'skip';

    public const ISSUE = 'issue';

    private function __construct(
        public readonly string $action,
        public readonly string $reason,
    ) {}

    public static function off(string $reason): self
    {
        return new self(self::OFF, $reason);
    }

    public static function wait(string $reason): self
    {
        return new self(self::WAIT, $reason);
    }

    public static function skip(string $reason): self
    {
        return new self(self::SKIP, $reason);
    }

    public static function issue(): self
    {
        return new self(self::ISSUE, 'NFC-e ao consumidor.');
    }

    /** Pedido que a emissão automática precisa tratar agora (emitir ou registrar que não emite). */
    public function settles(): bool
    {
        return in_array($this->action, [self::ISSUE, self::SKIP], true);
    }
}
