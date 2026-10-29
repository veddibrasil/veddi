<?php

namespace App\Support\Food99;

use App\DTOs\Food99OrderDTO;
use App\Models\Order;
use App\Support\Marketplace\MarketplaceOrderDetails;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Leitura do snapshot 99Food gravado em orders.external_metadata (ver Food99OrderDTO::toMetadata)
 * e das regras de etapa que dependem dele. Telas, kanban, comandas e Food99OrderActionService
 * usam esta classe pra que "pode despachar?" ou "falta aceitar?" tenham uma resposta só.
 */
class Food99OrderDetails implements MarketplaceOrderDetails
{
    /** Prazo da 99 pra loja confirmar antes do cancelamento automático (status 922). */
    public const CONFIRMATION_MINUTES = 5;

    /** Folga depois do prazo antes de esconder o botão de aceitar. */
    public const CONFIRMATION_GRACE_MINUTES = 2;

    /** Etapas do pedido na 99. CANCELLED fica fora: é terminal a partir de qualquer uma. */
    public const STAGE_RANK = [
        'CREATED' => 0,
        'CONFIRMED' => 1,
        'READY' => 2,
        'DISPATCHED' => 3,
        'ARRIVED' => 4,
        'FINISHED' => 5,
    ];

    /** status do pedido na 99 → etapa. */
    public const STATUS_STAGES = [
        100 => 'CREATED',
        200 => 'CONFIRMED',
        400 => 'DISPATCHED',
        500 => 'ARRIVED',
        600 => 'FINISHED',
    ];

    /** Motivo dos cancelamentos 9xx. */
    public const CANCEL_REASONS = [
        901 => 'Cancelado pelo cliente',
        902 => 'Pagamento expirou',
        903 => 'Prazo de retirada expirou',
        921 => 'Cancelado pela loja',
        922 => 'Loja não aceitou a tempo',
        923 => 'Loja fechada',
        941 => 'Cancelado pelo entregador',
        942 => 'Sem entregador disponível',
        943 => 'Prazo de coleta expirou',
        944 => 'Loja não entregou o pedido ao entregador',
        945 => 'Cliente não recebeu o pedido',
        946 => 'Atraso no preparo',
        961 => 'Cancelado pelo atendimento da 99Food',
        971 => 'Cancelado por análise de risco',
        981 => 'Cancelado pelo sistema da 99Food',
    ];

    /** Preferência do cliente pra item em falta (c_cancel_preference). */
    public const CANCEL_PREFERENCES = [
        5 => 'Entrar em contato',
        6 => 'Cancelar o pedido inteiro',
        7 => 'Reembolsar só o item em falta',
    ];

    public function __construct(private readonly Order $order) {}

    public static function for(Order $order): ?self
    {
        return $order->channel === '99food' ? new self($order) : null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->order->external_metadata[$key] ?? $default;
    }

    public function channelLabel(): string
    {
        return '99Food';
    }

    /** order_index: número curto do dia por loja (obrigatório na tela e no cupom). */
    public function displayId(): string
    {
        return (string) ($this->get('order_index') ?? $this->order->order_number);
    }

    /** order_id completo da 99 (int64 como string). */
    public function orderId(): string
    {
        return (string) ($this->get('food99_order_id') ?? $this->order->external_order_id);
    }

    /** Etapa na 99; pedido sem snapshot completo tem a etapa inferida do status local. */
    public function stage(): string
    {
        $stage = $this->get('food99_stage');
        if (is_string($stage) && $stage !== '') {
            return $stage;
        }

        return match ($this->order->status) {
            'preparing' => 'CONFIRMED',
            'ready' => 'READY',
            'out_for_delivery' => 'DISPATCHED',
            'delivered' => 'FINISHED',
            'cancelled', 'refunded' => 'CANCELLED',
            default => 'CREATED',
        };
    }

    public function stageRank(): int
    {
        return self::STAGE_RANK[$this->stage()] ?? -1;
    }

    public function reached(string $stage): bool
    {
        return $this->stage() !== 'CANCELLED' && $this->stageRank() >= (self::STAGE_RANK[$stage] ?? PHP_INT_MAX);
    }

    public function isFinished(): bool
    {
        return in_array($this->stage(), ['FINISHED', 'CANCELLED'], true)
            || in_array($this->order->status, ['delivered', 'cancelled', 'refunded'], true);
    }

    public function awaitingConfirmation(): bool
    {
        return ! $this->isFinished() && $this->stage() === 'CREATED';
    }

    public function confirmationMinutes(): int
    {
        return self::CONFIRMATION_MINUTES;
    }

    public function placedAt(): CarbonInterface
    {
        $placed = $this->get('placed_at');

        return is_string($placed)
            ? Carbon::parse($placed)->setTimezone(config('app.timezone'))
            : $this->order->created_at;
    }

    public function confirmationDeadline(): ?CarbonInterface
    {
        return $this->awaitingConfirmation()
            ? $this->placedAt()->copy()->addMinutes(self::CONFIRMATION_MINUTES)
            : null;
    }

    public function confirmationExpired(): bool
    {
        $deadline = $this->confirmationDeadline();

        return $deadline !== null && now()->greaterThan($deadline->copy()->addMinutes(self::CONFIRMATION_GRACE_MINUTES));
    }

    public function isScheduled(): bool
    {
        return false;
    }

    public function scheduleWindow(): ?string
    {
        return null;
    }

    public function deliveryType(): int
    {
        return (int) $this->get('delivery_type', $this->order->order_type === 'pickup' ? Food99OrderDTO::DELIVERY_TYPE_PICKUP : Food99OrderDTO::DELIVERY_TYPE_PLATFORM);
    }

    public function isPickup(): bool
    {
        return (int) $this->get('fulfillment_mode', 0) === 1 || $this->deliveryType() === Food99OrderDTO::DELIVERY_TYPE_PICKUP;
    }

    public function isDelivery(): bool
    {
        return ! $this->isPickup();
    }

    public function isDeliveredByPlatform(): bool
    {
        return $this->isDelivery() && $this->deliveryType() === Food99OrderDTO::DELIVERY_TYPE_PLATFORM;
    }

    public function isSelfDelivery(): bool
    {
        return $this->isDelivery() && $this->deliveryType() === Food99OrderDTO::DELIVERY_TYPE_STORE;
    }

    public function orderTypeLabel(): string
    {
        return match (true) {
            $this->isPickup() => 'Retirada pelo cliente',
            $this->isSelfDelivery() => 'Entrega própria',
            default => 'Entrega 99Food',
        };
    }

    public function canMarkReady(): bool
    {
        return ! $this->isFinished() && $this->stage() === 'CONFIRMED';
    }

    /** Despacho pela API só existe pra entrega da loja; entrega 99 sai com o entregador da 99. */
    public function canDispatch(): bool
    {
        return ! $this->isFinished() && $this->isSelfDelivery() && in_array($this->stage(), ['CONFIRMED', 'READY'], true);
    }

    public function canMarkDelivered(): bool
    {
        return ! $this->isFinished() && $this->isSelfDelivery() && $this->reached('DISPATCHED');
    }

    /** Retirada: a loja conclui quando o cliente busca (order/finish). */
    public function canFinishPickup(): bool
    {
        return ! $this->isFinished() && $this->isPickup() && $this->reached('CONFIRMED');
    }

    /** Entrega 99 em dinheiro: a loja confirma que recebeu do entregador (payConfirm). */
    public function canConfirmCashPayment(): bool
    {
        return ! $this->isFinished()
            && $this->isDeliveredByPlatform()
            && $this->isCash()
            && (float) $this->get('shop_paid_money', 0) > 0
            && $this->reached('CONFIRMED')
            && ! $this->get('cash_confirmed_at');
    }

    public function isCash(): bool
    {
        $channel = $this->get('pay_channel');

        return (int) $channel === 153 || ($channel === null && (int) $this->get('pay_method') === Food99OrderDTO::PAY_METHOD_ON_DELIVERY);
    }

    public function waitingMessage(): ?string
    {
        if ($this->isFinished()) {
            return null;
        }

        return match (true) {
            $this->stage() === 'READY' && $this->isDeliveredByPlatform() => 'Aguardando o entregador da 99Food retirar o pedido.',
            $this->reached('DISPATCHED') && $this->isDeliveredByPlatform() => 'Pedido a caminho. A 99Food conclui o pedido após a entrega.',
            $this->stage() === 'READY' && $this->isPickup() => 'Aguardando o cliente retirar. Marque como retirado quando ele buscar.',
            default => null,
        };
    }

    public function handoverCode(): ?string
    {
        return $this->get('handover_code');
    }

    public function takeawayCode(): ?string
    {
        return $this->get('takeaway_code') ?? $this->get('pickup_code');
    }

    public function customerPhone(): ?string
    {
        return $this->get('virtual_phone_number');
    }

    public function phoneLocalizer(): ?string
    {
        return $this->get('locator');
    }

    public function customerDocument(): ?string
    {
        return $this->get('customer_document');
    }

    public function remark(): ?string
    {
        return $this->get('remark');
    }

    public function needCutlery(): bool
    {
        return (bool) $this->get('need_cutlery', false);
    }

    public function cancelPreferenceLabel(): ?string
    {
        return self::CANCEL_PREFERENCES[(int) $this->get('c_cancel_preference', 0)] ?? null;
    }

    /** @return array{name: ?string, phone: ?string, eta: ?string, status: ?int}|null */
    public function rider(): ?array
    {
        $rider = $this->get('rider');

        return is_array($rider) ? $rider : null;
    }

    public function prepaidAmount(): float
    {
        return (float) $this->get('prepaid_amount', $this->order->total);
    }

    public function pendingAmount(): float
    {
        return (float) $this->get('pending_amount', 0.0);
    }

    public function paymentLines(): array
    {
        return array_map(function (array $method) {
            $changeFor = isset($method['change_for']) ? (float) $method['change_for'] : null;
            $value = (float) ($method['value'] ?? 0.0);

            return [
                'label' => (string) ($method['label'] ?? 'Pagamento'),
                'value' => $value,
                'prepaid' => (bool) ($method['prepaid'] ?? true),
                'change_for' => $changeFor,
                'change' => $changeFor !== null && $changeFor > $value ? round($changeFor - $value, 2) : null,
            ];
        }, $this->get('payment_methods', []));
    }

    public function benefitLines(): array
    {
        return array_map(function (array $promo) {
            $value = (float) ($promo['value'] ?? 0.0);
            $shop = (float) ($promo['shop_subsidy'] ?? 0.0);
            $money = fn (float $amount) => 'R$ '.number_format($amount, 2, ',', '.');
            $sponsors = collect([
                $shop > 0 ? $money($shop).' pago pela loja' : null,
                $value - $shop > 0.009 ? $money(round($value - $shop, 2)).' pago pela 99Food' : null,
            ])->filter()->implode(' + ');

            return ['label' => (string) ($promo['label'] ?? 'Promoção'), 'value' => $value, 'sponsors' => $sponsors];
        }, $this->get('promotions', []));
    }

    public function additionalFeeLines(): array
    {
        return array_map(fn (array $fee) => [
            'label' => (string) ($fee['label'] ?? 'Taxa'),
            'value' => (float) ($fee['value'] ?? 0.0),
        ], $this->get('additional_fees', []));
    }

    public function reportedTotal(): ?float
    {
        $total = $this->get('reported_total');

        return $total === null ? null : (float) $total;
    }

    public function cancellation(): ?array
    {
        return $this->get('cancellation');
    }

    public function isTest(): bool
    {
        return (bool) $this->get('is_test', false);
    }

    /**
     * Bloco 99Food das comandas (PDF e impressora térmica), em ASCII como o resto do cupom.
     * order_index e order_id saem sempre (requisito de go-live da 99). Cozinha e bar recebem só
     * número, tipo e talheres; geral e entrega levam códigos, contato, pagamento e promoções.
     *
     * @return array<int, array{text: string, bold: bool}>
     */
    public function receiptLines(string $station): array
    {
        $money = fn (float $value) => 'R$ '.number_format($value, 2, ',', '.');
        $lines = [
            ['text' => '99FOOD #'.$this->displayId(), 'bold' => true],
            ['text' => 'ID 99: '.$this->orderId(), 'bold' => false],
            ['text' => mb_strtoupper($this->orderTypeLabel()), 'bold' => true],
        ];

        if ($this->needCutlery()) {
            $lines[] = ['text' => 'ENVIAR TALHERES', 'bold' => true];
        }

        if (! in_array($station, ['geral', 'entrega'], true)) {
            return $this->ascii($lines);
        }

        if ($this->handoverCode()) {
            $lines[] = ['text' => 'Codigo p/ entregador: '.$this->handoverCode(), 'bold' => true];
        }
        if ($this->isPickup() && $this->takeawayCode()) {
            $lines[] = ['text' => 'Codigo de retirada: '.$this->takeawayCode(), 'bold' => true];
        }
        if ($this->customerPhone()) {
            $lines[] = ['text' => 'Tel. cliente: '.$this->customerPhone().($this->phoneLocalizer() ? ' (localizador '.$this->phoneLocalizer().')' : ''), 'bold' => false];
        }
        if ($this->customerDocument()) {
            $lines[] = ['text' => 'CPF na nota: '.$this->customerDocument(), 'bold' => false];
        }

        foreach ($this->paymentLines() as $payment) {
            $lines[] = ['text' => $payment['label'].': '.$money($payment['value']).($payment['prepaid'] ? ' - pago online' : ' - COBRAR NA ENTREGA'), 'bold' => ! $payment['prepaid']];
            if ($payment['change_for']) {
                $lines[] = ['text' => 'Troco para '.$money($payment['change_for']).($payment['change'] ? ' (levar '.$money($payment['change']).')' : ''), 'bold' => true];
            }
        }
        if ($this->pendingAmount() > 0 && ! $this->isDeliveredByPlatform()) {
            $lines[] = ['text' => 'COBRAR DO CLIENTE: '.$money($this->pendingAmount()), 'bold' => true];
        } elseif ($this->pendingAmount() > 0) {
            $lines[] = ['text' => 'Entregador 99 paga a loja: '.$money((float) $this->get('shop_paid_money', $this->pendingAmount())), 'bold' => true];
        }

        foreach ($this->benefitLines() as $benefit) {
            $lines[] = ['text' => $benefit['label'].': -'.$money($benefit['value']).($benefit['sponsors'] ? ' ('.$benefit['sponsors'].')' : ''), 'bold' => false];
        }

        if ($this->cancelPreferenceLabel()) {
            $lines[] = ['text' => 'Item em falta: '.$this->cancelPreferenceLabel(), 'bold' => false];
        }

        return $this->ascii($lines);
    }

    /** @param array<int, array{text: string, bold: bool}> $lines */
    private function ascii(array $lines): array
    {
        return array_map(fn (array $line) => ['text' => Str::ascii($line['text']), 'bold' => $line['bold']], $lines);
    }
}
