<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use Livewire\Attributes\Computed;

/**
 * Estado e validação do split de pagamento — dividir o valor do pedido/comanda em
 * várias partes, cada uma com método e valor próprios, somando o total. Usado tanto
 * por Terminal (venda direta) quanto TabTerminal (fechamento de comanda) via
 * HasPaymentFlow/HasOpenTabs, que chamam PaymentOrchestrator::processSplit().
 */
trait HasSplitPayment
{
    public bool $isSplitPayment = false;

    /** @var array<int, array{method: string, amount: string, cash_received: string, paid: bool}> */
    public array $splitPayments = [];

    /** Livewire chama automaticamente ao mudar $isSplitPayment (bound via wire:model.live no switch). */
    public function updatedIsSplitPayment(): void
    {
        $this->splitPayments = $this->isSplitPayment
            ? [
                ['method' => 'cash', 'amount' => '', 'cash_received' => '', 'paid' => false],
                ['method' => 'credit_card', 'amount' => '', 'cash_received' => '', 'paid' => false],
            ]
            : [];
    }

    /** Livewire chama automaticamente ao marcar/desmarcar isenção de taxa — redistribui o
     * valor removido (ou devolvido) proporcionalmente entre as partes já preenchidas do split,
     * em vez de deixar a soma desatualizada esperando ajuste manual. */
    public function updatedServiceFeeWaived(): void
    {
        $this->rescaleSplitPaymentsToTotal();
    }

    /** Idem updatedServiceFeeWaived(), pro couvert. */
    public function updatedCouvertFeeWaived(): void
    {
        $this->rescaleSplitPaymentsToTotal();
    }

    private function rescaleSplitPaymentsToTotal(): void
    {
        if (! $this->isSplitPayment || $this->splitPayments === []) {
            return;
        }

        unset($this->splitPaymentsTotal);
        $currentSum = $this->splitPaymentsTotal;

        if ($currentSum <= 0.0) {
            return;
        }

        $targetTotal = $this->cartTotalAfterDiscount;
        $ratio = $targetTotal / $currentSum;

        $this->splitPayments = array_map(function ($part) use ($ratio) {
            $part['amount'] = number_format(round(((float) str_replace(',', '.', $part['amount'] ?: 0)) * $ratio, 2), 2, '.', '');

            if ($part['method'] === 'cash' && filled($part['cash_received'] ?? null)) {
                $part['cash_received'] = number_format(round(((float) str_replace(',', '.', $part['cash_received'])) * $ratio, 2), 2, '.', '');
            }

            return $part;
        }, $this->splitPayments);

        // Corrige resíduo de arredondamento (centavos) na última parte, pra soma bater exatamente.
        unset($this->splitPaymentsTotal);
        $diff = round($targetTotal - $this->splitPaymentsTotal, 2);

        if (abs($diff) > 0.0) {
            $lastIndex = array_key_last($this->splitPayments);
            $lastAmount = (float) str_replace(',', '.', $this->splitPayments[$lastIndex]['amount']);
            $this->splitPayments[$lastIndex]['amount'] = number_format($lastAmount + $diff, 2, '.', '');

            if ($this->splitPayments[$lastIndex]['method'] === 'cash' && filled($this->splitPayments[$lastIndex]['cash_received'] ?? null)) {
                $lastReceived = (float) str_replace(',', '.', $this->splitPayments[$lastIndex]['cash_received']);
                $this->splitPayments[$lastIndex]['cash_received'] = number_format($lastReceived + $diff, 2, '.', '');
            }
        }
    }

    public function addSplitPart(): void
    {
        $this->splitPayments[] = ['method' => 'credit_card', 'amount' => '', 'cash_received' => '', 'paid' => false];
    }

    public function removeSplitPart(int $index): void
    {
        if (count($this->splitPayments) <= 2) {
            return;
        }

        unset($this->splitPayments[$index]);
        $this->splitPayments = array_values($this->splitPayments);
    }

    #[Computed]
    public function splitPaymentsTotal(): float
    {
        return round(array_sum(array_map(
            fn ($part) => (float) str_replace(',', '.', $part['amount'] ?: 0),
            $this->splitPayments
        )), 2);
    }

    #[Computed]
    public function splitPaymentsRemaining(): float
    {
        return round($this->cartTotalAfterDiscount - $this->splitPaymentsTotal, 2);
    }

    /** Soma só das partes marcadas como "pago" — usado pra acompanhar recebimento parcial
     * enquanto o operador vai cobrando cada parte (ex: cada convidado paga a sua). */
    #[Computed]
    public function splitPaymentsPaidAmount(): float
    {
        return round(array_sum(array_map(
            fn ($part) => ($part['paid'] ?? false) ? (float) str_replace(',', '.', $part['amount'] ?: 0) : 0.0,
            $this->splitPayments
        )), 2);
    }

    /** Quanto ainda falta receber, considerando só as partes já marcadas como pagas — distinto de
     * splitPaymentsRemaining(), que valida se a soma de TODAS as partes bate com o total. */
    #[Computed]
    public function splitPaymentsRemainingToCollect(): float
    {
        return round($this->cartTotalAfterDiscount - $this->splitPaymentsPaidAmount, 2);
    }

    private function effectivePaymentMethod(): string
    {
        return $this->isSplitPayment ? 'split' : $this->paymentMethod;
    }

    /** Valida $splitPayments. Retorna null se ok, senão a mensagem de erro. */
    private function validateSplitPayments(): ?string
    {
        if (count($this->splitPayments) < 2) {
            return 'Split precisa de ao menos duas partes.';
        }

        $cashCount = 0;
        foreach ($this->splitPayments as $part) {
            if (! in_array($part['method'], ['cash', 'credit_card', 'pix'], true)) {
                return 'Método de pagamento inválido em uma das partes.';
            }

            $amount = (float) str_replace(',', '.', $part['amount'] ?: 0);
            if ($amount <= 0) {
                return 'Todas as partes precisam de um valor maior que zero.';
            }

            if ($part['method'] === 'cash') {
                $cashCount++;
                $received = (float) str_replace(',', '.', $part['cash_received'] ?: $amount);
                if ($received < $amount) {
                    return 'Valor recebido em dinheiro não pode ser menor que a parte em dinheiro.';
                }
            }
        }

        if ($cashCount > 1) {
            return 'Só é permitida uma parte em dinheiro por split.';
        }

        if (abs($this->splitPaymentsRemaining) > 0.01) {
            return 'A soma das partes precisa bater exatamente com o total do pedido.';
        }

        return null;
    }

    /** @return array<int, array{method: string, amount: float, cash_received?: float}> */
    private function buildSplitPartsForOrchestrator(): array
    {
        return array_map(function ($part) {
            $amount = round((float) str_replace(',', '.', $part['amount']), 2);
            $result = ['method' => $part['method'], 'amount' => $amount];

            if ($part['method'] === 'cash') {
                $result['cash_received'] = round((float) str_replace(',', '.', $part['cash_received'] ?: $amount), 2);
            }

            return $result;
        }, $this->splitPayments);
    }
}
