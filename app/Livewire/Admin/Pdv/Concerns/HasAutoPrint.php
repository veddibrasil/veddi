<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Models\Order;

/** Compartilhado entre Terminal (HasPaymentFlow) e TabTerminal (HasOpenTabs) — os dois pagam um pedido. */
trait HasAutoPrint
{
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
