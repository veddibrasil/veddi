<?php

namespace App\Support\Orders;

use App\Models\Order;
use Carbon\CarbonInterface;

/**
 * Como o pedido sai da loja (entrega, retirada, mesa, balcão), para quando ele é e como
 * fica o pagamento na hora de soltar. Fonte única da fila da cozinha e dos cupons, para a
 * tela e o papel nunca discordarem.
 */
final class OrderFulfillment
{
    public const DELIVERY = 'delivery';

    public const PICKUP = 'pickup';

    public const TABLE = 'table';

    public const COUNTER = 'counter';

    /** Pago (online, no balcão ou pela plataforma): nada a cobrar de quem recebe. */
    public const PAYMENT_PAID = 'paid';

    /** Cobrar na entrega/retirada (dinheiro, maquininha, PIX na hora). */
    public const PAYMENT_COLLECT = 'collect';

    /** Pagamento online do chat ainda não confirmado pelo gateway. */
    public const PAYMENT_AWAITING_ONLINE = 'awaiting_online';

    private function __construct(private readonly Order $order) {}

    public static function for(Order $order): self
    {
        return new self($order);
    }

    public function kind(): string
    {
        $order = $this->order;

        if ($order->order_type === 'pdv') {
            return match (true) {
                (bool) $order->is_open_tab || filled($order->table_label) => self::TABLE,
                $order->delivery_address_id !== null => self::DELIVERY,
                $order->delivery_type === 'retirar' => self::PICKUP,
                default => self::COUNTER,
            };
        }

        return $order->order_type === 'pickup' ? self::PICKUP : self::DELIVERY;
    }

    /** Entrega ou retirada: pedido que alguém da loja precisa soltar para fora. */
    public function isHandoff(): bool
    {
        return in_array($this->kind(), [self::DELIVERY, self::PICKUP], true);
    }

    public function label(): string
    {
        return match ($this->kind()) {
            self::DELIVERY => 'Entrega',
            self::PICKUP => 'Retirada',
            self::TABLE => $this->order->table_label ?: ($this->order->restaurant_table_id ? 'Mesa' : 'Comanda'),
            default => 'Balcão',
        };
    }

    /** Horário combinado com o cliente (agendamento); null quando é para já. */
    public function dueAt(): ?CarbonInterface
    {
        return $this->order->scheduled_at?->copy()->setTimezone(config('app.timezone'));
    }

    /**
     * Situação do pagamento para quem solta o pedido.
     *
     * @return array{state: string, method: string, amount_due: float, change_for: ?float, change: ?float}
     */
    public function payment(): array
    {
        if ($marketplace = $this->order->marketplaceDetails()) {
            $pending = round($marketplace->pendingAmount(), 2);
            $collectLine = collect($marketplace->paymentLines())->first(fn (array $line) => ! $line['prepaid']);

            if ($pending > 0) {
                return $this->paymentResult(
                    self::PAYMENT_COLLECT,
                    $collectLine['label'] ?? $marketplace->channelLabel(),
                    $pending,
                    $collectLine['change_for'] ?? null,
                );
            }

            return $this->paymentResult(self::PAYMENT_PAID, $marketplace->channelLabel(), 0.0, null);
        }

        $order = $this->order;
        $method = $this->methodLabel();

        if ($order->needsPdvPaymentConfirmation()) {
            $cashReceived = (float) $order->cash_received;
            $changeFor = $order->payment_method === 'cash'
                ? ($this->changeForFromNotes() ?? ($cashReceived > (float) $order->total ? $cashReceived : null))
                : null;

            return $this->paymentResult(self::PAYMENT_COLLECT, $method, (float) $order->total, $changeFor);
        }

        $onlineChat = $order->order_type !== 'pdv' && ! $order->payment_on_delivery;
        if ($onlineChat && in_array($order->status, ['pending', 'awaiting_payment'], true)) {
            return $this->paymentResult(self::PAYMENT_AWAITING_ONLINE, $method, 0.0, null);
        }

        $paidOnline = $onlineChat && in_array($order->payment_method, ['pix', 'card', 'credit_card'], true);

        return $this->paymentResult(self::PAYMENT_PAID, $paidOnline ? "{$method} online" : $method, 0.0, null);
    }

    /** Uma linha para tela e cupom: "Pago · PIX online", "Cobrar R$ 45,00 · Dinheiro". */
    public function paymentHeadline(): string
    {
        $payment = $this->payment();

        return match ($payment['state']) {
            self::PAYMENT_COLLECT => 'Cobrar '.self::money($payment['amount_due']).' · '.$payment['method'],
            self::PAYMENT_AWAITING_ONLINE => 'Aguardando pagamento online',
            default => 'Pago · '.$payment['method'],
        };
    }

    /** "Troco para R$ 50,00 (levar R$ 5,00)", ou null sem troco. */
    public function changeLine(): ?string
    {
        $payment = $this->payment();

        if ($payment['change_for'] === null) {
            return null;
        }

        return 'Troco para '.self::money($payment['change_for'])
            .($payment['change'] ? ' (levar '.self::money($payment['change']).')' : '');
    }

    public static function money(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }

    /** @return array{state: string, method: string, amount_due: float, change_for: ?float, change: ?float} */
    private function paymentResult(string $state, string $method, float $amountDue, ?float $changeFor): array
    {
        $changeFor = $changeFor !== null && $changeFor > 0 ? round($changeFor, 2) : null;

        return [
            'state' => $state,
            'method' => $method,
            'amount_due' => $amountDue,
            'change_for' => $changeFor,
            'change' => $changeFor !== null && $changeFor > $amountDue ? round($changeFor - $amountDue, 2) : null,
        ];
    }

    private function methodLabel(): string
    {
        $order = $this->order;

        return match ($order->payment_method) {
            'pix', 'pix_manual' => 'PIX',
            'cash' => 'Dinheiro',
            'card_machine' => 'Maquininha',
            'card', 'credit_card' => $order->payment_on_delivery || $order->order_type === 'pdv' ? 'Cartão na maquininha' : 'Cartão',
            'split' => 'Dividido',
            null, '' => 'Não informado',
            default => ucfirst((string) $order->payment_method),
        };
    }

    /** O chat guarda o troco como linha das observações ("Troco para R$ 50,00"). */
    private function changeForFromNotes(): ?float
    {
        if (! preg_match('/Troco para R\$\s*([\d.]+,\d{2})/u', (string) $this->order->notes, $match)) {
            return null;
        }

        return (float) str_replace(['.', ','], ['', '.'], $match[1]);
    }
}
