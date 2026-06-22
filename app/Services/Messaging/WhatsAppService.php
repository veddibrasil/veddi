<?php

namespace App\Services\Messaging;

use App\Models\Order;
use App\Models\WhatsAppSetting;

class WhatsAppService
{
    public function send(string $phone, string $message, ?WhatsAppSetting $settings): bool
    {
        if ($settings === null || ! WhatsAppSetting::hasGlobalCredentials()) {
            return false;
        }

        $normalized = $this->normalizePhone($phone);

        if ($normalized === null) {
            return false;
        }

        // No WhatsApp provider configured (ZApi removed).
        return false;
    }

    public function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 13 && str_starts_with($digits, '55')) {
            return $digits;
        }

        if (strlen($digits) === 11) {
            return '55'.$digits;
        }

        // Telefone fixo (10 dígitos) não tem WhatsApp
        return null;
    }

    public function buildMessage(Order $order, string $event): string
    {
        $name = $order->customer?->name ?? 'Cliente';
        $number = $order->order_number;
        $company = $order->company?->name ?? '';
        $total = 'R$ '.number_format($order->total, 2, ',', '.');

        return match ($event) {
            'new_order' => "Olá {$name}! Seu pedido #{$number} foi recebido por {$company}. Total: {$total}.",

            'awaiting_payment' => $this->buildAwaitingPaymentMessage($order),

            'paid' => "✅ Pagamento confirmado! Seu pedido #{$number} foi pago e está sendo processado.",
            'preparing' => "👨‍🍳 Seu pedido #{$number} está sendo preparado. Em breve ficará pronto!",

            'ready' => $order->order_type === 'pickup'
                ? "🎉 Seu pedido #{$number} está pronto para retirada!"
                : "🚀 Seu pedido #{$number} saiu para entrega!",

            'delivered' => "📦 Pedido #{$number} entregue. Obrigado por comprar na {$company}! 🙏",
            'cancelled' => "❌ Seu pedido #{$number} foi cancelado. Dúvidas? Entre em contato conosco.",
            'refunded' => "💸 O reembolso do pedido #{$number} foi processado.",

            default => "Atualização do pedido #{$number}: {$event}.",
        };
    }

    private function buildAwaitingPaymentMessage(Order $order): string
    {
        $number = $order->order_number;
        $total = 'R$ '.number_format($order->total, 2, ',', '.');

        $base = "⏳ Pedido #{$number} ({$total}) aguardando pagamento.";

        $pixCode = $order->payment?->pix_copy_paste;
        if ($pixCode) {
            $base .= "\n\nCopie o código PIX:\n{$pixCode}";
        }

        return $base;
    }
}
