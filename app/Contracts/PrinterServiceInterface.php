<?php

namespace App\Contracts;

use App\Models\BranchPrinter;
use App\Models\Company;
use App\Models\FiscalNote;
use App\Models\Order;

interface PrinterServiceInterface
{
    public function testConnection(BranchPrinter $printer): bool;

    /**
     * Monta os bytes ESC/POS do cupom de um pedido para a estação informada
     * (geral/cozinha/bar/entrega). O caller decide para qual impressora
     * enviar — este método só monta o conteúdo.
     */
    public function buildOrderReceipt(Order $order, string $station, ?Company $company = null): string;

    /**
     * Monta os bytes ESC/POS do DANFE NFC-e completo (itens, totais, forma de
     * pagamento, consumidor, protocolo de autorização, chave de acesso e QR
     * code), a ser impresso quando a nota é autorizada.
     */
    public function buildFiscalNoteReceipt(FiscalNote $note, Order $order, ?Company $company = null): string;
}
