<?php

namespace App\Exceptions;

/**
 * Pendências que impedem a emissão, agrupadas para a tela (FiscalNoteService::check()).
 * Continua sendo RuntimeException: o job trata como falha de negócio, sem retry, e a
 * mensagem junta todas as pendências no texto que vai para o sino e para orders.fiscal_error.
 */
class FiscalPreflightException extends \RuntimeException
{
    public const GROUPS = [
        'issuer' => 'Emitente',
        'policy' => 'Documento fiscal',
        'recipient' => 'Destinatário',
        'products' => 'Produtos',
        'order' => 'Pedido',
    ];

    /**
     * @param  array<int, array{group: string, message: string, product_id?: ?int, product_name?: ?string, fields?: array<int, string>, source?: string, branch_id?: ?int, branch_name?: ?string}>  $issues
     */
    public function __construct(public readonly array $issues)
    {
        parent::__construct(implode(' ', array_unique(array_column($issues, 'message'))));
    }

    public static function single(string $group, string $message): self
    {
        return new self([['group' => $group, 'message' => $message]]);
    }
}
