<?php

namespace App\Support\Keeta;

use App\DTOs\KeetaOrderDTO;
use App\Models\Order;
use App\Support\Marketplace\MarketplaceOrderDetails;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Leitura do snapshot Keeta gravado em orders.external_metadata (ver KeetaOrderDTO::toMetadata)
 * e das regras de etapa que dependem dele. Telas, kanban, comandas e KeetaOrderActionService
 * usam esta classe pra que "pode despachar?" ou "falta aceitar?" tenham uma resposta só.
 */
class KeetaOrderDetails implements MarketplaceOrderDetails
{
    /** Prazo da Keeta pra loja confirmar; depois cancela e fecha a loja temporariamente. */
    public const CONFIRMATION_MINUTES = 5;

    /** Folga depois do prazo antes de esconder o botão de aceitar. */
    public const CONFIRMATION_GRACE_MINUTES = 2;

    /** Etapas do pedido na Keeta. CANCELLED fica fora: é terminal a partir de qualquer uma. */
    public const STAGE_RANK = [
        'CREATED' => 0,
        'CONFIRMED' => 1,
        'READY' => 2,
        'PICKUP_ONGOING' => 3,
        'DISPATCHED' => 4,
        'DELIVERED' => 5,
        'CONCLUDED' => 6,
    ];

    /** eventType da Keeta → etapa. */
    public const EVENT_STAGES = [
        'CREATED' => 'CREATED',
        'PLACED' => 'CREATED',
        'CONFIRMED' => 'CONFIRMED',
        'READY_FOR_PICKUP' => 'READY',
        'PICKUP_ONGOING' => 'PICKUP_ONGOING',
        'RIDER_ARRIVED_AT_STORE' => 'PICKUP_ONGOING',
        'ORDER_COLLECTED' => 'DISPATCHED',
        'PICKED_UP' => 'DISPATCHED',
        'DISPATCHED' => 'DISPATCHED',
        'DELIVERED' => 'DELIVERED',
        'CONCLUDED' => 'CONCLUDED',
    ];

    /** Códigos de cancelamento que a Keeta manda no evento CANCELLED (metadata.code). */
    public const CANCEL_REASONS = [
        'INSUFFICIENT_DISHES' => 'Itens em falta',
        'SUSPENSION_OF_BUSINESS' => 'Loja fechada',
        'INSUFFICIENT_MANPOWER' => 'Loja sem equipe',
        'OTHERS' => 'Outro motivo',
    ];

    public function __construct(private readonly Order $order) {}

    public static function for(Order $order): ?self
    {
        return $order->channel === 'keeta' ? new self($order) : null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->order->external_metadata[$key] ?? $default;
    }

    public function channelLabel(): string
    {
        return 'Keeta';
    }

    /** displayId: número curto que a Keeta mostra pra loja e pro cliente. */
    public function displayId(): string
    {
        return (string) ($this->get('display_id') ?? $this->order->order_number);
    }

    /** id completo do pedido na Keeta. */
    public function orderId(): string
    {
        return (string) ($this->get('keeta_order_id') ?? $this->order->external_order_id);
    }

    /** Etapa na Keeta; pedido sem snapshot completo tem a etapa inferida do status local. */
    public function stage(): string
    {
        $stage = $this->get('keeta_stage');
        if (is_string($stage) && $stage !== '') {
            return $stage;
        }

        return match ($this->order->status) {
            'preparing' => 'CONFIRMED',
            'ready' => 'READY',
            'out_for_delivery' => 'DISPATCHED',
            'delivered' => 'CONCLUDED',
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
        return in_array($this->stage(), ['DELIVERED', 'CONCLUDED', 'CANCELLED'], true)
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
        return $this->get('order_timing') === 'SCHEDULED' && $this->get('scheduled_start') !== null;
    }

    public function scheduleWindow(): ?string
    {
        if (! $this->isScheduled()) {
            return null;
        }

        $start = Carbon::parse($this->get('scheduled_start'))->setTimezone(config('app.timezone'));
        $end = $this->get('scheduled_end') ? Carbon::parse($this->get('scheduled_end'))->setTimezone(config('app.timezone')) : null;

        return $start->format('d/m H:i').($end ? ' às '.$end->format('H:i') : '');
    }

    public function isPickup(): bool
    {
        return in_array($this->get('order_type'), ['TAKEOUT', 'INDOOR'], true)
            || ($this->get('order_type') === null && $this->order->order_type === 'pickup');
    }

    public function isDelivery(): bool
    {
        return ! $this->isPickup();
    }

    public function isSelfDelivery(): bool
    {
        return $this->isDelivery() && $this->get('delivered_by') === KeetaOrderDTO::DELIVERED_BY_MERCHANT;
    }

    public function isDeliveredByPlatform(): bool
    {
        return $this->isDelivery() && ! $this->isSelfDelivery();
    }

    public function orderTypeLabel(): string
    {
        return match (true) {
            $this->isPickup() => 'Retirada pelo cliente',
            $this->isSelfDelivery() => 'Entrega própria',
            default => 'Entrega Keeta',
        };
    }

    public function canMarkReady(): bool
    {
        return ! $this->isFinished() && $this->stage() === 'CONFIRMED';
    }

    /** Despacho pela API só existe pra entrega da loja; entrega Keeta sai com o entregador da Keeta. */
    public function canDispatch(): bool
    {
        return ! $this->isFinished() && $this->isSelfDelivery() && in_array($this->stage(), ['CONFIRMED', 'READY'], true);
    }

    public function canMarkDelivered(): bool
    {
        return ! $this->isFinished() && $this->isSelfDelivery() && $this->reached('DISPATCHED');
    }

    /** A loja pediu o cancelamento e a Keeta ainda não confirmou com o evento CANCELLED. */
    public function cancellationPending(): bool
    {
        return ! $this->isFinished() && ($this->get('cancellation_request')['status'] ?? null) === 'requested';
    }

    public function waitingMessage(): ?string
    {
        if ($this->isFinished()) {
            return null;
        }

        return match (true) {
            $this->cancellationPending() => 'Cancelamento pedido à Keeta. Aguardando a confirmação.',
            $this->stage() === 'READY' && $this->isDeliveredByPlatform() => 'Aguardando o entregador da Keeta retirar o pedido.',
            $this->stage() === 'PICKUP_ONGOING' => 'Entregador da Keeta a caminho da loja.',
            $this->reached('DISPATCHED') && $this->isDeliveredByPlatform() => 'Pedido a caminho. A Keeta conclui o pedido após a entrega.',
            $this->stage() === 'READY' && $this->isPickup() => 'Aguardando o cliente retirar.',
            default => null,
        };
    }

    public function pickupCode(): ?string
    {
        return $this->get('pickup_code');
    }

    public function customerPhone(): ?string
    {
        return $this->get('customer_phone');
    }

    public function phoneLocalizer(): ?string
    {
        return null;
    }

    public function customerDocument(): ?string
    {
        return $this->get('customer_document');
    }

    public function remark(): ?string
    {
        return $this->get('remark');
    }

    /** @return array{pickup_eta: ?string}|null */
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
                $value - $shop > 0.009 ? $money(round($value - $shop, 2)).' pago pela Keeta' : null,
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
     * Bloco Keeta das comandas (PDF e impressora térmica), em ASCII como o resto do cupom.
     * Cozinha e bar recebem só número e tipo; geral e entrega levam código, contato,
     * pagamento e promoções.
     *
     * @return array<int, array{text: string, bold: bool}>
     */
    public function receiptLines(string $station): array
    {
        $money = fn (float $value) => 'R$ '.number_format($value, 2, ',', '.');
        $lines = [
            ['text' => 'KEETA #'.$this->displayId(), 'bold' => true],
            ['text' => 'ID Keeta: '.$this->orderId(), 'bold' => false],
            ['text' => mb_strtoupper($this->orderTypeLabel()), 'bold' => true],
        ];

        if ($this->isScheduled()) {
            $lines[] = ['text' => 'AGENDADO: '.$this->scheduleWindow(), 'bold' => true];
        }

        if (! in_array($station, ['geral', 'entrega'], true)) {
            return $this->ascii($lines);
        }

        if ($this->pickupCode()) {
            $lines[] = ['text' => 'Codigo de coleta: '.$this->pickupCode(), 'bold' => true];
        }
        if ($this->customerPhone()) {
            $lines[] = ['text' => 'Tel. cliente: '.$this->customerPhone(), 'bold' => false];
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
        if ($this->pendingAmount() > 0) {
            $lines[] = ['text' => 'COBRAR DO CLIENTE: '.$money($this->pendingAmount()), 'bold' => true];
        }

        foreach ($this->benefitLines() as $benefit) {
            $lines[] = ['text' => $benefit['label'].': -'.$money($benefit['value']).($benefit['sponsors'] ? ' ('.$benefit['sponsors'].')' : ''), 'bold' => false];
        }

        return $this->ascii($lines);
    }

    /** @param array<int, array{text: string, bold: bool}> $lines */
    private function ascii(array $lines): array
    {
        return array_map(fn (array $line) => ['text' => Str::ascii($line['text']), 'bold' => $line['bold']], $lines);
    }
}
