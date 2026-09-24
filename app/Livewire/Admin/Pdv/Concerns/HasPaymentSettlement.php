<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\PdvPaymentException;
use App\Models\Order;
use App\Services\Payment\PaymentOrchestrator;
use App\Support\MoneyInput;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Liquidação do pagamento (dinheiro, cartão, PIX manual ou split) e tratamento de falha —
 * compartilhado entre Terminal (venda direta, via HasPaymentFlow) e TabTerminal (fechamento de
 * comanda, via HasOpenTabs). Antes cada um tinha a própria cópia do bloco, e a validação de
 * "dinheiro recebido >= total" só poderia ser corrigida duas vezes.
 */
trait HasPaymentSettlement
{
    /**
     * Valor do campo "Valor recebido" no instante do clique/F10. O campo sincroniza com debounce
     * (`wire:model.live`); confirmar antes do debounce vencer deixaria o servidor com o valor antigo.
     */
    private function syncCashReceivedFromClient(?string $cashReceived): void
    {
        if ($cashReceived !== null && ! $this->isSplitPayment && $this->paymentMethod === 'cash') {
            $this->cashReceivedInput = mb_substr(trim($cashReceived), 0, 20);
        }
    }

    /**
     * Registra o pagamento do pedido conforme o método escolhido na tela. Deve rodar dentro de
     * transação — qualquer {@see PdvPaymentException} desfaz o pedido inteiro.
     */
    private function settleOrderPayment(Order $order): void
    {
        if ($this->isSplitPayment) {
            $parts = $this->buildSplitPartsForOrchestrator();
            $cashPart = collect($parts)->firstWhere('method', 'cash');

            if ($cashPart) {
                $order->cash_received = $cashPart['cash_received'];
                $order->cash_change = max(0.0, round($cashPart['cash_received'] - $cashPart['amount'], 2));
                $order->save();
            }

            $results = app(PaymentOrchestrator::class)->processSplit($order, $parts);
            $this->changeAmount = collect($results)->sum('change');

            return;
        }

        match ($this->paymentMethod) {
            'cash' => $this->settleCashPayment($order),
            'credit_card' => app(PaymentOrchestrator::class)->processCardMachine($order),
            'pix' => app(PaymentOrchestrator::class)->processPixManual($order),
            default => null,
        };
    }

    private function settleCashPayment(Order $order): void
    {
        $received = $this->resolveCashReceived((float) $order->total);

        $order->cash_received = $received;
        $order->cash_change = max(0.0, round($received - (float) $order->total, 2));
        $order->save();

        $result = app(PaymentOrchestrator::class)->processCash($order);
        $this->changeAmount = $result['change'];
    }

    /**
     * Valor recebido em dinheiro digitado pelo operador (vazio = valor exato). O servidor não pode
     * aceitar menos que o total: o `min` do input é só HTML e o pedido nasceria pago com troco
     * negativo escondido.
     *
     * @throws PdvPaymentException
     */
    private function resolveCashReceived(float $total): float
    {
        $total = round($total, 2);

        if (blank($this->cashReceivedInput)) {
            return $total;
        }

        $received = MoneyInput::parse($this->cashReceivedInput);

        if ($received === null) {
            throw new PdvPaymentException('Valor recebido em dinheiro inválido.');
        }

        $received = round($received, 2);

        if ($received < $total) {
            throw new PdvPaymentException(sprintf(
                'Valor recebido (R$ %s) é menor que o total (R$ %s).',
                number_format($received, 2, ',', '.'),
                number_format($total, 2, ',', '.'),
            ));
        }

        return $received;
    }

    /**
     * Efeito colateral que roda DEPOIS do commit (nota fiscal, impressão, broadcast). Se falhar, o
     * pedido já existe e está pago — mostrar erro ao operador e deixá-lo refazer o pedido gera
     * duplicidade. Só registra no log e segue.
     */
    private function afterCommit(string $effect, Order $order, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::channel('orders')->error('Falha em efeito pós-pagamento no PDV (pedido já gravado)', [
                'effect' => $effect,
                'order_id' => $order->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Traduz a falha da transação em mensagem para o operador — sem vazar mensagem crua de infraestrutura. */
    private function reportPaymentFailure(\Throwable $e): void
    {
        if ($e instanceof QueryException) {
            // Erro de SQL (ex.: colisão de order_number entre terminais concorrentes)
            // não deve chegar cru pro caixa — só orienta a tentar de novo.
            Log::channel('orders')->error('Falha de banco ao processar pedido no PDV', [
                'error' => $e->getMessage(),
            ]);
            $this->addError('order', 'Não foi possível processar o pedido agora. Tente novamente.');

            return;
        }

        // Mensagens em pt-BR pensadas pro operador: regra do PDV, estoque e os RuntimeException
        // "puros" que os services lançam (produto indisponível na filial, soma do split...).
        // Subclasses de infraestrutura (PDOException, erros HTTP dos gateways) ficam de fora.
        $isUserFacing = $e instanceof PdvPaymentException
            || $e instanceof InsufficientStockException
            || $e::class === \RuntimeException::class;

        if ($isUserFacing) {
            $this->addError('order', $e->getMessage());

            return;
        }

        Log::channel('orders')->error('Falha inesperada ao processar pedido no PDV', [
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
        $this->addError('order', 'Não foi possível processar o pedido agora. Tente novamente.');
    }
}
