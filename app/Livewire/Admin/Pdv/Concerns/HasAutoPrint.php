<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Models\BranchPrinter;
use App\Models\Order;

/** Compartilhado entre Terminal (HasPaymentFlow) e TabTerminal (HasOpenTabs) — os dois pagam um pedido. */
trait HasAutoPrint
{
    public function getListeners(): array
    {
        $companyId = app()->bound('current.company') ? app('current.company')->id : null;

        return $companyId
            ? [
                "echo:orders.{$companyId},NewOrderPlaced" => 'onOrderBroadcastReceived',
                "echo:orders.{$companyId},TabOrderSentToProduction" => 'onTabOrderSentToProductionBroadcast',
                "echo:orders.{$companyId},TabItemsReadyForProduction" => 'onTabItemsReadyForProductionBroadcast',
            ]
            : [];
    }

    /**
     * Pedido nascido fora do PDV (chat/site) chegou — se a filial aberta nesta tela tem
     * impressora ativa com auto_print em cozinha/bar, dispara o cupom sozinho, sem
     * esperar o operador clicar em nada. Pedido com order_type 'pdv' é ignorado aqui:
     * ele já tem seu próprio gatilho de impressão local (pagamento no balcão ou
     * "Finalizar Pedido" na comanda) — reagir ao broadcast dele também duplicaria a via.
     *
     * Não há trava de "só a primeira tela imprime": se a empresa tiver mais de uma tela
     * de PDV aberta na filial, cada uma tenta imprimir com a impressora que tiver
     * pareada no próprio QZ Tray. Preferimos o risco raro de via duplicada (duas telas
     * realmente pareadas com a mesma impressora) a uma tela sem impressora nenhuma
     * "ganhar a corrida" e o pedido nunca sair impresso em lugar nenhum.
     */
    public function onOrderBroadcastReceived(array $event): void
    {
        if (($event['order_type'] ?? null) === 'pdv') {
            return;
        }

        if ((int) ($event['branch_id'] ?? 0) !== (int) $this->selectedBranchId) {
            return;
        }

        $stations = collect([
            'cozinha' => $event['is_kitchen'] ?? false,
            'bar' => $event['is_bar'] ?? false,
        ])->filter()->keys()->values();

        if ($stations->isEmpty()) {
            return;
        }

        $printers = BranchPrinter::where('branch_id', $this->selectedBranchId)
            ->where('auto_print', true)
            ->where('active', true)
            ->whereIn('station', $stations)
            ->pluck('station')
            ->values();

        if ($printers->isEmpty()) {
            return;
        }

        $this->dispatch('order-paid', orderId: $event['order_id'], stations: $printers, printFiscalNote: false);
    }

    /**
     * Garçom mandou a comanda pra produção — pode ter clicado do celular, sem QZ Tray.
     * Toda tela de PDV aberta na filial recebe o broadcast e tenta imprimir com a
     * impressora que tiver pareada; mesma lógica de {@see onOrderBroadcastReceived()}
     * sobre não travar em "só a primeira imprime".
     */
    public function onTabOrderSentToProductionBroadcast(array $event): void
    {
        if ((int) ($event['branch_id'] ?? 0) !== (int) $this->selectedBranchId) {
            return;
        }

        $stations = collect($event['stations'] ?? [])->values();

        if ($stations->isEmpty()) {
            return;
        }

        $this->dispatch('tab-order-finalized', orderId: $event['order_id'], stations: $stations);
    }

    /**
     * Item novo lançado numa comanda em qualquer tela da filial — o que precisa ser
     * impresso (item+quantidade) já veio pronto no payload, decidido atomicamente
     * no momento do lançamento (ver HasOpenTabs::notifyPendingProductionItems).
     * Aqui só repassa pro JS montar e mandar pro QZ Tray.
     */
    public function onTabItemsReadyForProductionBroadcast(array $event): void
    {
        if ((int) ($event['branch_id'] ?? 0) !== (int) $this->selectedBranchId) {
            return;
        }

        $stations = collect($event['stations'] ?? [])->filter(fn ($items) => ! empty($items));

        if ($stations->isEmpty()) {
            return;
        }

        $this->dispatch('tab-items-pending', orderId: $event['order_id'], stations: $stations);
    }

    /**
     * Manda pro JS quais impressoras da filial imprimem sozinhas — sem isso o
     * front teria que descobrir a config de auto_print com uma chamada extra
     * antes de poder imprimir o cupom assim que a tela de sucesso aparece.
     * printFiscalNote controla só a impressão automática da nota (a emissão em
     * si independe disso — é obrigação fiscal); por isso o evento ainda dispara
     * mesmo sem impressora com auto_print, quando o operador marcou a opção.
     *
     * $includeReceiptStations=false pro fechamento de comanda: o cupom de
     * cozinha/bar já saiu no botão "Finalizar Pedido" (via EscPosPrinterService
     * estação 'geral'), reimprimir no fechamento duplicaria a via — só a nota
     * fiscal (se marcada) importa nesse momento.
     */
    private function dispatchAutoPrintPayload(Order $order, bool $includeReceiptStations = true): void
    {
        $printers = $includeReceiptStations
            ? $order->branch->printers()
                ->where('auto_print', true)
                ->where('active', true)
                ->get(['station'])
                ->pluck('station')
                ->values()
            : collect();

        if ($printers->isEmpty() && ! $this->printFiscalNote) {
            return;
        }

        $this->dispatch('order-paid', orderId: $order->id, stations: $printers, printFiscalNote: $this->printFiscalNote);
    }
}
