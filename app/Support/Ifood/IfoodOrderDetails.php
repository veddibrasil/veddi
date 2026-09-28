<?php

namespace App\Support\Ifood;

use App\Models\Order;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Leitura do snapshot iFood gravado em orders.external_metadata (ver IfoodOrderDTO::toMetadata)
 * e das regras de etapa que dependem dele. Tela do pedido, kanban, comandas e
 * IfoodOrderActionService usam esta classe, pra que "pode despachar?" ou "falta aceitar?"
 * tenham uma resposta só.
 */
class IfoodOrderDetails
{
    /** Prazo do iFood pra confirmar um pedido imediato antes do cancelamento automático. */
    public const CONFIRMATION_MINUTES = 8;

    /**
     * Folga depois do prazo antes de esconder o botão de aceitar: passado isso, o pedido
     * não confirmado já foi cancelado pelo iFood (se foi aceito no Gestor de Pedidos, o
     * evento CONFIRMED já teria atualizado a etapa).
     */
    public const CONFIRMATION_GRACE_MINUTES = 7;

    /** Ordem das etapas no iFood. CANCELLED fica fora: é terminal a partir de qualquer uma. */
    public const STATUS_RANK = [
        'PLACED' => 0,
        'CONFIRMED' => 1,
        'PREPARATION_STARTED' => 2,
        'READY_TO_PICKUP' => 3,
        'DISPATCHED' => 4,
        'CONCLUDED' => 5,
    ];

    private const PAYMENT_METHOD_LABELS = [
        'CREDIT' => 'Crédito',
        'DEBIT' => 'Débito',
        'MEAL_VOUCHER' => 'Vale-refeição',
        'FOOD_VOUCHER' => 'Vale-alimentação',
        'DIGITAL_WALLET' => 'Carteira digital',
        'PIX' => 'PIX',
        'CASH' => 'Dinheiro',
        'CREDIT_DEBIT' => 'Crédito/Débito',
        'GIFT_CARD' => 'Vale-presente',
        'BANK_PAY' => 'Pagamento bancário',
        'OTHER' => 'Outro',
    ];

    private const SPONSOR_LABELS = [
        'IFOOD' => 'pelo iFood',
        'MERCHANT' => 'pela loja',
        'EXTERNAL' => 'pelo parceiro externo',
        'CHAIN' => 'pela rede',
    ];

    public function __construct(private readonly Order $order) {}

    public static function for(Order $order): ?self
    {
        return $order->channel === 'ifood' ? new self($order) : null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->order->external_metadata[$key] ?? $default;
    }

    public function displayId(): string
    {
        return (string) ($this->get('display_id') ?? $this->order->order_number);
    }

    /**
     * Etapa do pedido no iFood. Pedidos gravados antes do snapshot completo não têm
     * ifood_status; nesse caso a etapa é inferida do status local.
     */
    public function status(): string
    {
        $status = $this->get('ifood_status');
        if (is_string($status) && $status !== '') {
            return $status;
        }

        return match ($this->order->status) {
            'preparing' => 'CONFIRMED',
            'ready' => 'READY_TO_PICKUP',
            'out_for_delivery' => 'DISPATCHED',
            'delivered' => 'CONCLUDED',
            'cancelled', 'refunded' => 'CANCELLED',
            default => 'PLACED',
        };
    }

    public function statusRank(): int
    {
        return self::STATUS_RANK[$this->status()] ?? -1;
    }

    public function reached(string $ifoodStatus): bool
    {
        return $this->status() !== 'CANCELLED' && $this->statusRank() >= (self::STATUS_RANK[$ifoodStatus] ?? PHP_INT_MAX);
    }

    public function isFinished(): bool
    {
        return in_array($this->status(), ['CONCLUDED', 'CANCELLED'], true)
            || in_array($this->order->status, ['delivered', 'cancelled', 'refunded'], true);
    }

    public function awaitingConfirmation(): bool
    {
        return ! $this->isFinished() && $this->status() === 'PLACED';
    }

    public function isScheduled(): bool
    {
        return $this->get('order_timing') === 'SCHEDULED';
    }

    public function placedAt(): CarbonInterface
    {
        $placed = $this->get('created_at');

        return is_string($placed)
            ? Carbon::parse($placed)->setTimezone(config('app.timezone'))
            : $this->order->created_at;
    }

    /** Prazo pra aceitar (só pedido imediato; o agendado tem janela própria no iFood). */
    public function confirmationDeadline(): ?CarbonInterface
    {
        if (! $this->awaitingConfirmation() || $this->isScheduled()) {
            return null;
        }

        return $this->placedAt()->copy()->addMinutes(self::CONFIRMATION_MINUTES);
    }

    public function confirmationExpired(): bool
    {
        $deadline = $this->confirmationDeadline();

        return $deadline !== null && now()->greaterThan($deadline->copy()->addMinutes(self::CONFIRMATION_GRACE_MINUTES));
    }

    public function orderType(): string
    {
        return (string) ($this->get('order_type') ?? ($this->order->order_type === 'delivery' ? 'DELIVERY' : 'TAKEOUT'));
    }

    public function orderTypeLabel(): string
    {
        return match ($this->orderType()) {
            'DELIVERY' => $this->isDeliveredByIfood() ? 'Entrega pelo iFood' : 'Entrega própria',
            'TAKEOUT' => 'Retirada pelo cliente',
            'DINE_IN', 'INDOOR' => 'Consumo no local',
            default => $this->orderType(),
        };
    }

    public function isDelivery(): bool
    {
        return $this->orderType() === 'DELIVERY';
    }

    public function isDeliveredByIfood(): bool
    {
        return $this->isDelivery() && $this->get('delivered_by') === 'IFOOD';
    }

    /** Despacho pela API só existe pra entrega feita pela própria loja; entrega iFood é despachada pelo entregador. */
    public function canBeDispatchedByStore(): bool
    {
        return $this->isDelivery() && ! $this->isDeliveredByIfood();
    }

    public function canStartPreparation(): bool
    {
        return $this->isScheduled() && $this->status() === 'CONFIRMED' && ! $this->isFinished();
    }

    public function canMarkReady(): bool
    {
        return ! $this->isFinished() && in_array($this->status(), ['CONFIRMED', 'PREPARATION_STARTED'], true);
    }

    public function canDispatch(): bool
    {
        return ! $this->isFinished() && $this->canBeDispatchedByStore() && $this->status() === 'READY_TO_PICKUP';
    }

    /** O que falta acontecer do lado do iFood depois da última ação da loja. */
    public function waitingMessage(): ?string
    {
        if ($this->isFinished()) {
            return null;
        }

        return match (true) {
            $this->status() === 'READY_TO_PICKUP' && $this->isDeliveredByIfood() => 'Aguardando o entregador do iFood retirar o pedido.',
            $this->status() === 'READY_TO_PICKUP' && ! $this->isDelivery() => 'Aguardando o cliente retirar. O iFood conclui o pedido.',
            $this->status() === 'DISPATCHED' => 'Pedido a caminho. O iFood conclui o pedido após a entrega.',
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
        return $this->get('phone_localizer');
    }

    public function phoneLocalizerExpiresAt(): ?CarbonInterface
    {
        $value = $this->get('phone_localizer_expires_at');

        return is_string($value) ? Carbon::parse($value)->setTimezone(config('app.timezone')) : null;
    }

    public function customerDocument(): ?string
    {
        return $this->get('customer_document');
    }

    public function extraInfo(): ?string
    {
        return $this->get('extra_info');
    }

    public function deliveryObservations(): ?string
    {
        return $this->get('delivery_observations');
    }

    public function deliveryReference(): ?string
    {
        return $this->get('delivery_reference');
    }

    public function indoorTable(): ?string
    {
        return $this->get('indoor_table');
    }

    public function scheduleWindow(): ?string
    {
        $start = $this->get('schedule_start');
        if (! is_string($start)) {
            return null;
        }

        $tz = config('app.timezone');
        $from = Carbon::parse($start)->setTimezone($tz);
        $end = $this->get('schedule_end');
        $to = is_string($end) ? Carbon::parse($end)->setTimezone($tz) : null;

        return $from->format('d/m/Y H:i').($to ? ' às '.$to->format('H:i') : '');
    }

    public function preparationStartAt(): ?CarbonInterface
    {
        $value = $this->get('preparation_start_at');

        return is_string($value) ? Carbon::parse($value)->setTimezone(config('app.timezone')) : null;
    }

    public function prepaidAmount(): float
    {
        return (float) $this->get('prepaid_amount', $this->order->total);
    }

    /** Valor que a loja cobra do cliente na entrega/retirada (dinheiro ou maquininha). */
    public function pendingAmount(): float
    {
        return (float) $this->get('pending_amount', 0.0);
    }

    /**
     * Linhas de pagamento prontas pra exibir: método, bandeira/carteira, valor,
     * se já foi pago online e o troco.
     *
     * @return array<int, array{label: string, value: float, prepaid: bool, change_for: ?float, change: ?float}>
     */
    public function paymentLines(): array
    {
        return array_map(function (array $method) {
            $label = self::PAYMENT_METHOD_LABELS[$method['method'] ?? ''] ?? ($method['method'] ?? 'Pagamento');
            $detail = $method['brand'] ?? $method['wallet'] ?? null;
            $changeFor = isset($method['change_for']) ? (float) $method['change_for'] : null;
            $value = (float) ($method['value'] ?? 0.0);

            return [
                'label' => $detail ? "{$label} ({$detail})" : $label,
                'value' => $value,
                'prepaid' => (bool) ($method['prepaid'] ?? true),
                'change_for' => $changeFor,
                'change' => $changeFor !== null && $changeFor > $value ? round($changeFor - $value, 2) : null,
            ];
        }, $this->get('payment_methods', []));
    }

    /**
     * Cupons/descontos e quem paga cada parte (iFood, loja...).
     *
     * @return array<int, array{label: string, value: float, sponsors: string}>
     */
    public function benefitLines(): array
    {
        return array_map(function (array $benefit) {
            $target = match ($benefit['target'] ?? null) {
                'DELIVERY_FEE' => 'Cupom no frete',
                'ITEM' => 'Cupom no item',
                default => 'Cupom',
            };
            $sponsors = collect($benefit['sponsors'] ?? [])
                ->filter(fn ($sponsor) => (float) ($sponsor['value'] ?? 0) > 0)
                ->map(fn ($sponsor) => 'R$ '.number_format((float) $sponsor['value'], 2, ',', '.').' pago '.(self::SPONSOR_LABELS[$sponsor['name'] ?? ''] ?? 'por '.($sponsor['name'] ?? '?')))
                ->implode(' + ');

            return [
                'label' => $benefit['campaign'] ? "{$target} ({$benefit['campaign']})" : $target,
                'value' => (float) ($benefit['value'] ?? 0.0),
                'sponsors' => $sponsors,
            ];
        }, $this->get('benefits', []));
    }

    /** @return array<int, array{label: string, value: float}> */
    public function additionalFeeLines(): array
    {
        return array_map(fn (array $fee) => [
            'label' => $fee['description'] ?? $fee['type'] ?? 'Taxa adicional',
            'value' => (float) ($fee['value'] ?? 0.0),
        ], $this->get('additional_fees', []));
    }

    public function reportedTotal(): ?float
    {
        $total = $this->get('ifood_reported_total');

        return $total === null ? null : (float) $total;
    }

    /** @return array{status: string, reason_code: ?string, reason: ?string, at: ?string}|null */
    public function cancellationRequest(): ?array
    {
        return $this->get('cancellation_request');
    }

    /** @return array{origin: ?string, code: ?string, reason: ?string}|null */
    public function cancellation(): ?array
    {
        return $this->get('cancellation');
    }

    public function isTest(): bool
    {
        return (bool) $this->get('is_test', false);
    }

    /**
     * Bloco iFood das comandas (PDF e impressora térmica), em ASCII como o resto do cupom.
     * Cozinha e bar recebem só número e tipo; geral e entrega levam contato, código de
     * coleta, pagamento/troco, cupom e observação de entrega.
     *
     * @return array<int, array{text: string, bold: bool}>
     */
    public function receiptLines(string $station): array
    {
        $money = fn (float $value) => 'R$ '.number_format($value, 2, ',', '.');
        $lines = [
            ['text' => 'IFOOD #'.$this->displayId(), 'bold' => true],
            ['text' => mb_strtoupper($this->orderTypeLabel()), 'bold' => true],
        ];

        if ($this->isScheduled() && $this->scheduleWindow()) {
            $lines[] = ['text' => 'Agendado: '.$this->scheduleWindow(), 'bold' => true];
        }

        if ($this->indoorTable()) {
            $lines[] = ['text' => 'Mesa: '.$this->indoorTable(), 'bold' => true];
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
        if ($this->phoneLocalizer()) {
            $expires = $this->phoneLocalizerExpiresAt();
            $lines[] = ['text' => 'Localizador: '.$this->phoneLocalizer().($expires ? ' (ate '.$expires->format('d/m H:i').')' : ''), 'bold' => true];
        }
        if ($this->customerDocument()) {
            $lines[] = ['text' => 'CPF/CNPJ na nota: '.$this->customerDocument(), 'bold' => false];
        }

        foreach ($this->paymentLines() as $payment) {
            $lines[] = ['text' => $payment['label'].': '.$money($payment['value']).($payment['prepaid'] ? ' - pago online' : ' - COBRAR NA ENTREGA'), 'bold' => ! $payment['prepaid']];
            if ($payment['change_for']) {
                $lines[] = ['text' => 'Troco para '.$money($payment['change_for']).($payment['change'] ? ' (levar '.$money($payment['change']).')' : ''), 'bold' => true];
            }
        }
        if ($this->pendingAmount() > 0) {
            $lines[] = ['text' => 'COBRAR DO CLIENTE: '.$money($this->pendingAmount()), 'bold' => true];
        } elseif ($this->paymentLines() === []) {
            $lines[] = ['text' => 'Pago online pelo iFood', 'bold' => false];
        }

        foreach ($this->benefitLines() as $benefit) {
            $lines[] = ['text' => $benefit['label'].': -'.$money($benefit['value']).($benefit['sponsors'] ? ' ('.$benefit['sponsors'].')' : ''), 'bold' => false];
        }

        if ($this->deliveryObservations()) {
            $lines[] = ['text' => ($this->isDelivery() ? 'Obs. entrega: ' : 'Obs.: ').$this->deliveryObservations(), 'bold' => true];
        }
        if ($this->deliveryReference()) {
            $lines[] = ['text' => 'Referencia: '.$this->deliveryReference(), 'bold' => false];
        }

        return $this->ascii($lines);
    }

    /** @param array<int, array{text: string, bold: bool}> $lines */
    private function ascii(array $lines): array
    {
        return array_map(fn (array $line) => ['text' => Str::ascii($line['text']), 'bold' => $line['bold']], $lines);
    }
}
