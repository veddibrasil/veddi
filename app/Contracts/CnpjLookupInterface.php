<?php

namespace App\Contracts;

interface CnpjLookupInterface
{
    /**
     * Dados cadastrais públicos do CNPJ já no formato do destinatário fiscal
     * ({@see \App\Services\Fiscal\FiscalRecipient}), ou null quando a consulta falha
     * ou o CNPJ não existe — quem chama cai pro preenchimento manual.
     *
     * @return array{name: string, email: ?string, street: ?string, number: ?string, complement: ?string, neighborhood: ?string, city: ?string, state: ?string, zip: ?string, status: ?string}|null
     */
    public function find(string $cnpj): ?array;
}
