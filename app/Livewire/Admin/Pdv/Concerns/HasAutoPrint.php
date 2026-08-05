<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Models\BranchPrinter;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;

/** Compartilhado entre Terminal (HasPaymentFlow) e TabTerminal (HasOpenTabs) — os dois pagam um pedido. */
trait HasAutoPrint
{
    public function getListeners(): array
    {
        $companyId = app()->bound('current.company') ? app('current.company')->id : null;

        return $companyId
            ? ["echo:orders.{$companyId},NewOrderPlaced" => 'onOrderBroadcastReceived']
            : [];
    }

    /**
     * Pedido nascido fora do PDV (chat/site) chegou — se a filial aberta nesta tela tem
     * impressora ativa com auto_print em cozinha/bar, dispara o cupom sozinho, sem
     * esperar o operador clicar em nada. Pedido com order_type 'pdv' é ignorado aqui:
     * ele já tem seu próprio gatilho de impressão local (pagamento no balcão ou
     * "Finalizar Pedido" na comanda) — reagir ao broadcast dele também duplicaria a via.
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

        // Trava por pedido: se a filial tiver mais de uma tela de PDV aberta ouvindo o
        // mesmo canal, só a primeira a processar o evento imprime — sem isso cada tela
        // aberta manda o cupom pra impressora de rede, duplicando a via física.
        if (! Cache::add('auto-print-order-'.$event['order_id'], true, now()->addMinutes(2))) {
            return;
        }

        $this->dispatch('order-paid', orderId: $event['order_id'], stations: $printers, printFiscalNote: false);
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
