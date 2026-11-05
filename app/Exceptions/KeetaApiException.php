<?php

namespace App\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Recusa da API da Keeta (HTTP fora de 2xx, com o corpo de erro do Open Delivery
 * {title, status}) ou falha de transporte. getMessage() segue técnico (vai pro log com
 * operação e HTTP); userMessage() explica pro restaurante.
 */
class KeetaApiException extends RuntimeException
{
    /** Status local pra falha sem resposta HTTP (rede, timeout, corpo inválido). */
    public const TRANSPORT_STATUS = 0;

    public function __construct(
        string $message,
        public readonly int $httpStatus = self::TRANSPORT_STATUS,
        public readonly ?string $title = null,
        public readonly string $operation = '',
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(string $operation, int $status, ?string $title, ?int $integrationId = null): self
    {
        $message = "Keeta: falha em {$operation} (HTTP {$status}".($integrationId ? ", integration_id={$integrationId}" : '').')';
        $class = $status === 429 ? KeetaRateLimitedException::class : self::class;

        return new $class($message, $status, $title !== null ? Str::limit(trim($title), 160) : null, $operation);
    }

    /**
     * Ação repetida ou fora de ordem: a Keeta responde 422 "order status is not in a valid
     * state". Quem chama trata como já feito e espera o evento.
     */
    public function isStateConflict(): bool
    {
        return $this->httpStatus === 422;
    }

    public function isAuthError(): bool
    {
        return in_array($this->httpStatus, [401, 403], true);
    }

    public function isNotFound(): bool
    {
        return $this->httpStatus === 404;
    }

    public function userMessage(): string
    {
        return match (true) {
            $this->httpStatus === 401 => 'A Keeta recusou o acesso da plataforma. Avise o suporte do VEDDI.',
            $this->httpStatus === 403 => 'A Keeta não liberou o acesso desta loja. Confira se a autorização do app VEDDI continua ativa no portal da Keeta.',
            $this->httpStatus === 404 => 'A Keeta não encontrou o pedido ou a loja informada.',
            $this->httpStatus === 422 => 'O pedido já mudou de etapa na Keeta. Atualize a tela.',
            $this->httpStatus === 429 => 'A Keeta limita a frequência desta operação. Tente de novo em instantes.',
            $this->httpStatus === self::TRANSPORT_STATUS || $this->httpStatus >= 500 => 'A Keeta está instável no momento. Tente de novo em instantes.',
            $this->title !== null && $this->title !== '' => "A Keeta recusou: {$this->title}.",
            default => "A Keeta recusou a operação (HTTP {$this->httpStatus}).",
        };
    }

    /** Pro canal keeta: fora das recusas da API, a mensagem pode trazer dados do cliente (SQL com bindings). */
    public static function forLog(\Throwable $e): string
    {
        return $e instanceof self ? $e->getMessage() : class_basename($e).' em '.basename($e->getFile()).':'.$e->getLine();
    }

    /** Pra tela: recusa da API ganha o motivo; as demais exceções do fluxo Keeta já vêm com texto pro usuário. */
    public static function messageFor(\Throwable $e): string
    {
        return $e instanceof self ? $e->userMessage() : $e->getMessage();
    }
}
