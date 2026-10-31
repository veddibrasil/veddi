<?php

namespace App\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Recusa da API da 99Food (envelope com errno != 0, ou resposta sem envelope). getMessage()
 * segue técnico (vai pro log com operação, errno e requestId); userMessage() explica pro
 * restaurante.
 */
class Food99ApiException extends RuntimeException
{
    /** Loja sem token: não vinculada ao app, vínculo desfeito ou falha da 99 ao emitir. */
    public const SHOP_ACCESS_ERRNOS = [10100, 10101, 10103];

    public const TOKEN_EXPIRED_ERRNO = 10102;

    public const TOKEN_REJECTED_ERRNO = 10100;

    public const RATE_LIMIT_ERRNOS = [10005, 12021];

    public const APP_CREDENTIAL_ERRNOS = [14105, 14106];

    /** errno local pra falha sem envelope (rede, HTTP 5xx, corpo inválido). */
    public const TRANSPORT_ERRNO = -1;

    public function __construct(
        string $message,
        public readonly int $errno = self::TRANSPORT_ERRNO,
        public readonly ?string $errmsg = null,
        public readonly ?string $requestId = null,
        public readonly string $operation = '',
    ) {
        parent::__construct($message);
    }

    public static function fromEnvelope(string $operation, int $errno, ?string $errmsg, ?string $requestId, ?int $integrationId = null): self
    {
        $message = "99Food: falha em {$operation} (errno {$errno}".($integrationId ? ", integration_id={$integrationId}" : '').($requestId ? ", requestId={$requestId}" : '').')';
        $class = match (true) {
            $errno === self::TOKEN_EXPIRED_ERRNO => Food99TokenExpiredException::class,
            in_array($errno, self::RATE_LIMIT_ERRNOS, true) => Food99RateLimitedException::class,
            default => self::class,
        };

        return new $class($message, $errno, $errmsg !== null ? Str::limit(trim($errmsg), 160) : null, $requestId, $operation);
    }

    public function isShopAccessError(): bool
    {
        return in_array($this->errno, self::SHOP_ACCESS_ERRNOS, true);
    }

    /** A 99 recusou o auth_token enviado: vencido (10102) ou trocado por um novo vínculo (10100). */
    public function isTokenRejected(): bool
    {
        return in_array($this->errno, [self::TOKEN_EXPIRED_ERRNO, self::TOKEN_REJECTED_ERRNO], true);
    }

    public function userMessage(): string
    {
        return match (true) {
            $this->isShopAccessError() => 'A 99Food não liberou o acesso desta loja. Reconecte a integração nas configurações da 99Food.',
            $this->errno === self::TOKEN_EXPIRED_ERRNO => 'O acesso à 99Food expirou e não foi renovado. Tente de novo em instantes.',
            in_array($this->errno, self::APP_CREDENTIAL_ERRNOS, true) => 'As credenciais da plataforma na 99Food estão inválidas. Avise o suporte do VEDDI.',
            in_array($this->errno, self::RATE_LIMIT_ERRNOS, true) => 'A 99Food limita a frequência desta operação. Tente de novo em instantes.',
            $this->errno === self::TRANSPORT_ERRNO => 'A 99Food está instável no momento. Tente de novo em instantes.',
            $this->errmsg !== null && $this->errmsg !== '' => "A 99Food recusou: {$this->errmsg}.",
            default => "A 99Food recusou a operação (erro {$this->errno}).",
        };
    }

    /** Pro canal food99: fora das recusas da API, a mensagem pode trazer dados do cliente (SQL com bindings). */
    public static function forLog(\Throwable $e): string
    {
        return $e instanceof self ? $e->getMessage() : class_basename($e).' em '.basename($e->getFile()).':'.$e->getLine();
    }

    /** Pra tela: recusa da API ganha o motivo; as demais exceções do fluxo 99Food já vêm com texto pro usuário. */
    public static function messageFor(\Throwable $e): string
    {
        return $e instanceof self ? $e->userMessage() : $e->getMessage();
    }
}
