<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Recusa da API do iFood. getMessage() segue técnico (vai pro log, com ids); userMessage()
 * explica pro restaurante usando o motivo que o iFood devolveu no corpo da resposta.
 */
class IfoodApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }

    public static function fromResponse(string $operation, int $integrationId, Response $response): self
    {
        return new self(
            "iFood: falha em {$operation} (integration_id={$integrationId}, status {$response->status()})",
            $response->status(),
            self::reasonFrom($response->json()),
        );
    }

    /**
     * Motivo no corpo do iFood: {error: {code, message, details: [{field, message}]}} ou {message}.
     * Os details vêm primeiro porque o message de validação é genérico ("PatchItemStatusDto is not valid").
     */
    public static function reasonFrom(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }
        $error = is_array($body['error'] ?? null) ? $body['error'] : $body;
        $details = collect(is_array($error['details'] ?? null) ? $error['details'] : [])
            ->pluck('message')->filter(fn ($message) => is_string($message) && trim($message) !== '')->unique();
        $reason = $details->isNotEmpty() ? $details->implode('; ') : ($error['message'] ?? null);

        return is_string($reason) ? self::clean($reason) : null;
    }

    public function userMessage(): string
    {
        return match (true) {
            in_array($this->status, [401, 403], true) => 'O iFood recusou o acesso desta loja. Reconecte a integração nas configurações do iFood.',
            $this->status === 429 || $this->status >= 500 => 'O iFood está instável no momento. Tente de novo em instantes.',
            $this->status === 409 && preg_match('/item with .+ already exist/i', $this->reason ?? '') === 1 => 'O iFood já tem um item igual a este no cardápio (mesmo produto e categoria). Apague o item repetido no Portal do Parceiro e sincronize de novo.',
            $this->reason !== null => "O iFood recusou: {$this->reason}.",
            $this->status > 0 => "O iFood recusou a operação (erro {$this->status}).",
            default => 'O iFood não concluiu a atualização. Tente de novo em instantes.',
        };
    }

    /** Pra tela: recusa da API ganha o motivo; as demais exceções do fluxo iFood já vêm com texto pro usuário. */
    public static function messageFor(\Throwable $e): string
    {
        return $e instanceof self ? $e->userMessage() : $e->getMessage();
    }

    /** Tira ids de recurso (ficam no log) e limita o tamanho pra caber no cadastro do produto. */
    private static function clean(string $reason): ?string
    {
        $reason = preg_replace('/\s*\(existing item id [^)]*\)|:\s*\[[^\]]*\]\s*$/i', '', $reason);
        $reason = trim(preg_replace('/\s+/', ' ', $reason), ' .');

        return $reason === '' ? null : Str::limit($reason, 160);
    }
}
