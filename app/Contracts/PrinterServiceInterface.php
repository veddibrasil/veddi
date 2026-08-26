<?php

namespace App\Contracts;

use App\Models\BranchPrinter;
use App\Models\Company;
use App\Models\FiscalNote;
use App\Models\Order;
use Illuminate\Support\Collection;

interface PrinterServiceInterface
{
    public function testConnection(BranchPrinter $printer): bool;

    /**
     * Monta os bytes ESC/POS do cupom de um pedido para a estação informada
     * (geral/cozinha/bar/entrega). O caller decide para qual impressora
     * enviar — este método só monta o conteúdo.
     *
     * $full ignora o filtro por categoria da estação e imprime todos os itens
     * do pedido mesmo em cozinha/bar — usado no "Finalizar Pedido" da mesa,
     * onde a mesma via completa vai pra cada impressora configurada, servindo
     * de guia de entrega pro garçom além de ticket de preparo.
     *
     * $itemsOverride ignora completamente $full e o filtro por estação, e
     * imprime só os itens (e quantidades) informados — usado no aviso
     * automático de item novo lançado na comanda, pra não reimprimir os itens
     * que a cozinha/bar já recebeu.
     */
    public function buildOrderReceipt(Order $order, string $station, ?Company $company = null, bool $full = false, ?Collection $itemsOverride = null): string;

    /**
     * Monta os bytes ESC/POS do DANFE NFC-e completo (itens, totais, forma de
     * pagamento, consumidor, protocolo de autorização, chave de acesso e QR
     * code), a ser impresso quando a nota é autorizada.
     */
    public function buildFiscalNoteReceipt(FiscalNote $note, Order $order, ?Company $company = null): string;

    /**
     * Monta os bytes ESC/POS do fechamento de pedidos do dia (delivery, PDV e
     * geral), no mesmo formato do relatório em PDF gerado por
     * OrderClosingReportService::build().
     */
    public function buildClosingReceipt(array $report, ?Company $company = null): string;
}
