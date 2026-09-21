<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Enums\OrderCancellationReason;
use App\Models\Order;
use App\Services\Order\OrderCancellationPolicy;
use App\Services\Order\OrderService;
use App\Services\Order\StockService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

trait HasOrderCancellation
{
    /**
     * Abre o modal de cancelamento pra um pedido específico. O modal guarda seu
     * próprio id/número (não depende de `$lastOrderId`) e só fecha por ação
     * explícita do operador — `closeCancelOrderModal()` ou `cancelPdvOrder()`
     * bem-sucedido — nunca sozinho (erro de validação ou de regra de negócio
     * mantém o modal aberto com a mensagem visível). Busca o número no servidor
     * (em vez de receber via parâmetro) pra não depender de interpolar o valor
     * num atributo `wire:click`.
     */
    public function openCancelOrderModal(int $orderId): void
    {
        $this->cancelModalOrderId = $orderId;
        $this->cancelModalOrderNumber = Order::withoutGlobalScopes()->find($orderId)?->order_number ?? '';
        $this->resetCancelReasonFields();
    }

    public function closeCancelOrderModal(): void
    {
        $this->cancelModalOrderId = null;
        $this->cancelModalOrderNumber = '';
        $this->resetCancelReasonFields();
    }

    public function cancelLastOrder(): void
    {
        if ($this->lastOrderId) {
            $this->cancelPdvOrder($this->lastOrderId);
        }
    }

    /**
     * Cancela um pedido do PDV. Motivo é obrigatório (select com justificativas
     * pré-definidas + descrição livre, exigida quando o motivo é "Outro") e o
     * usuário logado fica registrado como operador do cancelamento — grava em
     * `orders.cancelled_by`, no histórico de auditoria (`OrderStatusHistory`,
     * visível ao admin nos detalhes do pedido) e no `PdvAuditLog` da sessão.
     */
    public function cancelPdvOrder(int $orderId): void
    {
        abort_unless(! $this->isWaiter, 403);

        $this->validate([
            'cancelReasonCode' => ['required', Rule::enum(OrderCancellationReason::class)],
            'cancelReasonDescription' => [
                Rule::requiredIf($this->cancelReasonCode === OrderCancellationReason::Other->value),
                'nullable', 'string', 'max:500',
            ],
        ], [
            'cancelReasonCode.required' => 'Selecione o motivo do cancelamento.',
            'cancelReasonDescription.required' => 'Descreva o motivo do cancelamento.',
        ]);

        $company = app('current.company');

        $order = Order::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('order_type', 'pdv')
            ->find($orderId);

        if (! $order || in_array($order->status, ['cancelled', 'refunded'])) {
            $this->failCancel('Pedido não encontrado ou já cancelado.');

            return;
        }

        // Allow cancel for any order linked to current session, or fallback to branch + session time
        $isSessionOrder = $this->cashSessionId && (
            $order->pdv_cash_session_id === $this->cashSessionId
            || ($order->branch_id === $this->selectedBranchId && $this->cashSession && $order->created_at >= $this->cashSession->created_at)
        );

        if (! $isSessionOrder) {
            $this->failCancel('Pedido não pertence à sessão atual.');

            return;
        }

        try {
            app(OrderCancellationPolicy::class)->authorizeAdminCancel($order);
        } catch (\RuntimeException $e) {
            $this->failCancel($e->getMessage());

            return;
        }

        $previousStatus = $order->status;
        $reasonOption = OrderCancellationReason::from($this->cancelReasonCode);
        $reasonText = trim($this->cancelReasonDescription) !== ''
            ? "{$reasonOption->label()}: {$this->cancelReasonDescription}"
            : $reasonOption->label();

        $order->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reasonText,
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
        ]);
        app(StockService::class)->restoreForOrder($order);

        app(OrderService::class)->recordStatusHistory($order, auth()->id(), $previousStatus, 'cancelled', $reasonText, [
            'source' => 'pdv_cancel',
        ]);

        $this->dispatch('pdv-toast', message: "Pedido {$order->order_number} cancelado.");

        Log::channel('orders')->info('Pedido PDV cancelado pelo operador', [
            'order_id' => $order->id,
            'user_id' => auth()->id(),
            'reason' => $reasonText,
        ]);

        $this->audit('order_cancelled', [
            'order_id' => $order->id,
            'amount' => (float) $order->total,
            'reason' => $reasonText,
            'metadata' => [
                'order_number' => $order->order_number,
            ],
        ]);

        $this->closeCancelOrderModal();

        if ($this->lastOrderId === $orderId) {
            // Só limpa o card de sucesso, não o carrinho — o operador pode já ter
            // começado a montar o próximo pedido enquanto cancelava este.
            $this->dismissOrderSuccess();
        }
    }

    private function resetCancelReasonFields(): void
    {
        $this->cancelReasonCode = '';
        $this->cancelReasonDescription = '';
        $this->resetErrorBag(['cancelReasonCode', 'cancelReasonDescription']);
    }

    /**
     * Erro de cancelamento vai pro error bag (exibido inline nos dois pontos que já
     * têm @error('cancel')) e também via toast — o card de sucesso pode já ter sido
     * fechado/expirado quando o erro chega, então o inline sozinho não é confiável.
     */
    private function failCancel(string $message): void
    {
        $this->addError('cancel', $message);
        $this->dispatch('pdv-toast', message: $message);
    }
}
