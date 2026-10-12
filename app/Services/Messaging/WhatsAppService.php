<?php

namespace App\Services\Messaging;

use App\DTOs\WhatsAppSender;
use App\Enums\OrderChannel;
use App\Models\Company;
use App\Models\Order;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Log;

/**
 * Regras de decisão das notificações de pedido por WhatsApp: qual template, qual
 * remetente e se o envio é permitido. O envio em si é do provider (Contract).
 */
class WhatsAppService
{
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

    /**
     * Formas em que um número (wa_id) pode estar gravado: sem DDI (como o chat guarda em
     * customers.phone) e com DDI (como whatsapp_messages.to_phone). A Meta às vezes entrega
     * números brasileiros sem o 9º dígito, então as duas grafias entram.
     *
     * @return array<int, string>
     */
    public function phoneCandidates(string $waId): array
    {
        $digits = preg_replace('/\D/', '', $waId);
        $local = str_starts_with($digits, '55') && strlen($digits) >= 12 ? substr($digits, 2) : $digits;

        $variants = [$local];

        if (strlen($local) === 11 && $local[2] === '9') {
            $variants[] = substr($local, 0, 2).substr($local, 3);
        } elseif (strlen($local) === 10) {
            $variants[] = substr($local, 0, 2).'9'.substr($local, 2);
        }

        return array_values(array_unique([
            ...$variants,
            ...array_map(fn (string $number) => '55'.$number, $variants),
        ]));
    }

    /**
     * Template do evento para o pedido, ou null se o evento não tem template
     * (ex.: awaiting_payment) ou faltam dados para preencher as variáveis.
     *
     * 'event' devolvido é a chave de config/whatsapp_templates.php: 'ready' vira
     * ready_pickup ou ready_delivery conforme o tipo do pedido.
     *
     * @return array{event: string, name: string, language: string, params: array<int, string>}|null
     */
    public function resolveTemplate(Order $order, string $event): ?array
    {
        $templateEvent = $event === 'ready'
            ? ($order->isDeliveryOrder() ? 'ready_delivery' : 'ready_pickup')
            : $event;

        $definition = config("whatsapp_templates.templates.{$templateEvent}");

        if (! is_array($definition)) {
            return null;
        }

        $params = [];
        foreach ($definition['variables'] as $variable) {
            $value = $this->templateVariable($order, $variable);

            // A Meta rejeita parâmetro vazio; melhor não enviar do que enviar template quebrado.
            if ($value === '') {
                Log::channel('whatsapp')->warning('Template sem dado para variável, envio ignorado', [
                    'order_id' => $order->id,
                    'event' => $templateEvent,
                    'variable' => $variable,
                ]);

                return null;
            }

            $params[] = $value;
        }

        return [
            'event' => $templateEvent,
            'name' => $definition['name'],
            'language' => (string) config('whatsapp_templates.language', 'pt_BR'),
            'params' => $params,
        ];
    }

    /** Conexão ativa da empresa; senão número da plataforma se o fallback estiver ligado; senão null. */
    public function resolveSender(Company $company): ?WhatsAppSender
    {
        return $this->senderFor($this->activeConnection($company));
    }

    /**
     * A empresa notifica por WhatsApp de fato: notificações ligadas e algum remetente disponível
     * (conexão ativa ou fallback). É o critério para pedir o consentimento do cliente no chat.
     */
    public function isActiveFor(Company $company): bool
    {
        return (bool) $company->whatsappSetting?->enabled && $this->resolveSender($company) !== null;
    }

    /**
     * No fallback (número da plataforma) os templates contam como aprovados via config:
     * quem mantém a WABA da plataforma é a Veddi.
     */
    public function hasApprovedTemplate(Company $company, string $templateEvent): bool
    {
        return $this->templateApproved($this->activeConnection($company), $templateEvent);
    }

    /**
     * Tudo que o envio precisa, ou null se a notificação não deve sair. Usado pelo
     * listener (decidir despachar) e pelo job (revalidar no momento do envio: opt-out,
     * desconexão e toggles podem mudar entre o despacho e a execução).
     *
     * @return array{template: array{event: string, name: string, language: string, params: array<int, string>}, sender: WhatsAppSender, phone: string}|null
     */
    public function prepareNotification(Order $order, string $event): ?array
    {
        $company = $order->company;
        $settings = $company?->whatsappSetting;

        // Falha barata primeiro: a maioria das empresas não tem WhatsApp ligado.
        if (! $settings?->isEventEnabled($event)) {
            return null;
        }

        if (! $this->isNotifiableOrder($order)) {
            return $this->skip($order, $event, 'canal ou tipo de pedido fora do escopo');
        }

        // Pedido em dinheiro nasce 'paid' mas o pagamento só acontece na entrega.
        if ($event === 'paid' && strtoupper((string) $order->payment_method) === 'CASH') {
            return $this->skip($order, $event, 'pagamento em dinheiro na entrega');
        }

        $template = $this->resolveTemplate($order, $event);
        if ($template === null) {
            return $this->skip($order, $event, 'evento sem template');
        }

        $customer = $order->customer;
        if (! $customer || ! $customer->canReceiveWhatsApp()) {
            return $this->skip($order, $event, 'cliente sem opt-in ou com opt-out');
        }

        $phone = $this->normalizePhone((string) $customer->phone);
        if ($phone === null) {
            return $this->skip($order, $event, 'telefone sem WhatsApp');
        }

        $connection = $this->activeConnection($company);

        $sender = $this->senderFor($connection);
        if ($sender === null) {
            return $this->skip($order, $event, 'sem conexão ativa nem fallback');
        }

        if (! $this->templateApproved($connection, $template['event'])) {
            return $this->skip($order, $event, 'template não aprovado');
        }

        return ['template' => $template, 'sender' => $sender, 'phone' => $phone];
    }

    /** Já existe (ou já foi tentada) notificação deste evento para o pedido. */
    public function alreadyNotified(Order $order, string $event): bool
    {
        return WhatsAppMessage::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('event', $event)
            ->exists();
    }

    public function shouldNotify(Order $order, string $event): bool
    {
        return $this->prepareNotification($order, $event) !== null
            && ! $this->alreadyNotified($order, $event);
    }

    /**
     * Conexão ativa da empresa. Busca sem o CompanyScope e filtrando company_id de forma
     * explícita: o current.company pode ser outro (super admin, jobs) ou nem existir.
     */
    private function activeConnection(Company $company): ?WhatsAppConnection
    {
        $connection = WhatsAppConnection::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->first();

        if (! $connection?->isActive() || blank($connection->phone_number_id) || blank($connection->access_token)) {
            return null;
        }

        return $connection;
    }

    private function senderFor(?WhatsAppConnection $connection): ?WhatsAppSender
    {
        if ($connection) {
            return WhatsAppSender::fromConnection($connection);
        }

        return config('services.whatsapp.fallback_to_platform') ? WhatsAppSender::platform() : null;
    }

    private function templateApproved(?WhatsAppConnection $connection, string $templateEvent): bool
    {
        if ($connection) {
            return $connection->approvedTemplate($templateEvent) !== null;
        }

        return config("whatsapp_templates.templates.{$templateEvent}") !== null;
    }

    /** Só pedidos feitos no chat público (não PDV nem iFood), mesmo que o cliente tenha opt-in. */
    private function isNotifiableOrder(Order $order): bool
    {
        return ($order->channel ?: OrderChannel::Chat->value) === OrderChannel::Chat->value
            && $order->order_type !== 'pdv';
    }

    private function templateVariable(Order $order, string $variable): string
    {
        return match ($variable) {
            'customer_name' => trim((string) $order->customer?->name) ?: 'Cliente',
            'order_number' => (string) $order->order_number,
            'total' => 'R$ '.number_format((float) $order->total, 2, ',', '.'),
            'scheduled_at' => (string) $order->scheduled_at?->setTimezone(config('app.timezone'))->format('d/m/Y \à\s H:i'),
            default => '',
        };
    }

    private function skip(Order $order, string $event, string $reason): null
    {
        Log::channel('whatsapp')->debug('Notificação WhatsApp ignorada', [
            'order_id' => $order->id,
            'event' => $event,
            'reason' => $reason,
        ]);

        return null;
    }
}
