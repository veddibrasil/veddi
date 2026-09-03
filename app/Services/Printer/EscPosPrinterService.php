<?php

namespace App\Services\Printer;

use App\Contracts\PrinterServiceInterface;
use App\Models\BranchPrinter;
use App\Models\Company;
use App\Models\FiscalNote;
use App\Models\Order;
use Illuminate\Support\Collection;
use Mike42\Escpos\PrintConnectors\MemoryPrintConnector;
use Mike42\Escpos\Printer;

class EscPosPrinterService implements PrinterServiceInterface
{
    private const CONNECT_TIMEOUT_SECONDS = 3;

    private const STATION_LABELS = [
        'geral' => null,
        'cozinha' => 'COZINHA',
        'bar' => 'BAR',
        'entrega' => 'ENTREGA',
    ];

    public function testConnection(BranchPrinter $printer): bool
    {
        // Impressora USB fala com o navegador do caixa via driver do SO, não pela
        // rede — o backend não tem como alcançá-la daqui, então não há o que testar.
        if ($printer->connection_type === 'usb') {
            return true;
        }

        $socket = @fsockopen($printer->ip_address, $printer->port, $errorCode, $errorMessage, self::CONNECT_TIMEOUT_SECONDS);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    public function buildOrderReceipt(Order $order, string $station, ?Company $company = null, bool $full = false, ?Collection $itemsOverride = null): string
    {
        $connector = new MemoryPrintConnector;
        $printer = new Printer($connector);

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text($company?->name ?? config('app.name')."\n");
        $printer->setEmphasis(false);
        $printer->text($order->branch->name."\n");

        $label = self::STATION_LABELS[$station] ?? null;

        if ($label !== null) {
            $printer->feed();
            $printer->setEmphasis(true);
            $printer->text($itemsOverride !== null ? "== {$label} - ITEM NOVO ==\n" : "== {$label} ==\n");
            $printer->setEmphasis(false);
        }

        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $this->divider($printer);

        if ($order->isDeliveryOrder()) {
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->setEmphasis(true);
            $printer->text("*** ENTREGA ***\n");
            $printer->setEmphasis(false);
            $printer->setJustification(Printer::JUSTIFY_LEFT);
        }

        if ($order->scheduled_at) {
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->setEmphasis(true);
            $printer->text('*** AGENDADO: '.$order->scheduled_at->setTimezone(config('app.timezone'))->format('d/m/Y H:i')." ***\n");
            $printer->setEmphasis(false);
            $printer->setJustification(Printer::JUSTIFY_LEFT);
        }

        $printer->text("Pedido: {$order->order_number}\n");

        if ($order->table_label) {
            $printer->text("Mesa/Comanda: {$order->table_label}\n");
        }

        $printer->text('Data: '.$order->created_at->format('d/m/Y H:i')."\n");

        $isDeliveryTicket = $station === 'entrega';
        $isGeneralReceipt = $station === 'geral';

        if ($isDeliveryTicket) {
            $this->divider($printer);
            $this->printCustomerAndAddress($printer, $order);
        }

        $this->divider($printer);

        $items = match (true) {
            $itemsOverride !== null => $itemsOverride,
            $full, in_array($station, ['geral', 'entrega'], true) => $order->items,
            default => $order->items->filter(fn ($item) => $item->matchesStation($station))->values(),
        };

        if ($items->isEmpty()) {
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("Nenhum item desta estacao neste pedido.\n");
            $printer->setJustification(Printer::JUSTIFY_LEFT);
        }

        foreach ($items as $item) {
            $printer->setEmphasis(true);
            $printer->text("{$item->quantity}x {$item->product_name}\n");
            $printer->setEmphasis(false);

            if ($isGeneralReceipt) {
                $printer->text('  R$ '.number_format((float) $item->unit_price, 2, ',', '.')." / un.\n");
            }

            foreach ($item->options ?? [] as $group) {
                if (empty($group['selections'])) {
                    continue;
                }

                $printer->text('  '.($group['group_name'] ?? 'Opcoes').":\n");

                foreach ($group['selections'] as $selection) {
                    $printer->text("    - {$selection['qty']}x {$selection['name']}\n");
                }
            }

            if ($isGeneralReceipt) {
                $printer->setJustification(Printer::JUSTIFY_RIGHT);
                $printer->text('R$ '.number_format((float) $item->subtotal, 2, ',', '.')."\n");
                $printer->setJustification(Printer::JUSTIFY_LEFT);
            }
        }

        if ($isGeneralReceipt) {
            $this->divider($printer);
            $this->printTotals($printer, $order);
            $this->divider($printer);
            $this->printPayment($printer, $order);
            $this->divider($printer);
            $this->printCustomerAndAddress($printer, $order);
        } elseif ($isDeliveryTicket && $order->payment_method === 'cash' && ! $order->payment) {
            $this->divider($printer);
            $printer->setEmphasis(true);
            $printer->text('COBRAR: R$ '.number_format((float) $order->total, 2, ',', '.')."\n");
            $printer->setEmphasis(false);
        }

        if ($order->notes) {
            $this->divider($printer);
            $printer->text("Obs: {$order->notes}\n");
        }

        if ($isGeneralReceipt) {
            $this->divider($printer);
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("Obrigado pela preferencia!\n");
        }

        $printer->feed(2);
        $printer->cut();

        $data = $connector->getData();
        $printer->close();

        return $data;
    }

    public function buildFiscalNoteReceipt(FiscalNote $note, Order $order, ?Company $company = null): string
    {
        $connector = new MemoryPrintConnector;
        $printer = new Printer($connector);
        $branch = $order->branch;
        $raw = $note->raw_response;

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text($company?->name ?? config('app.name')."\n");
        $printer->setEmphasis(false);

        if ($company?->owner_cpf_cnpj) {
            $printer->text('CNPJ: '.$company->owner_cpf_cnpj."\n");
        }

        if ($branch) {
            $printer->text($branch->address.($branch->number ? ', '.$branch->number : '')."\n");
            $printer->text($branch->neighborhood.($branch->city ? ' - '.$branch->city : '')."\n");
        }

        $printer->feed();
        $printer->setEmphasis(true);
        $printer->text("DANFE NFC-e\n");
        $printer->setEmphasis(false);
        $printer->text("Documento Auxiliar da Nota Fiscal\nde Consumidor Eletronica\n");
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $this->divider($printer);
        $printer->text("Pedido: {$order->order_number}\n");
        $printer->text('Data: '.$order->created_at->format('d/m/Y H:i')."\n");
        $this->divider($printer);

        foreach ($order->items as $item) {
            $printer->setEmphasis(true);
            $printer->text("{$item->quantity}x {$item->product_name}\n");
            $printer->setEmphasis(false);
            $printer->text('  R$ '.number_format((float) $item->unit_price, 2, ',', '.')." / un.\n");

            $printer->setJustification(Printer::JUSTIFY_RIGHT);
            $printer->text('R$ '.number_format((float) $item->subtotal, 2, ',', '.')."\n");
            $printer->setJustification(Printer::JUSTIFY_LEFT);
        }

        $this->divider($printer);
        $this->printTotals($printer, $order);
        $this->divider($printer);
        $this->printPayment($printer, $order);
        $this->divider($printer);

        $customerDocument = $note->data['customer_cpf_cnpj'] ?? null;

        if ($customerDocument) {
            $printer->text("CPF/CNPJ do consumidor:\n{$customerDocument}\n");
        } else {
            $printer->text("CONSUMIDOR NAO IDENTIFICADO\n");
        }

        $this->divider($printer);

        if (! empty($raw['numero'])) {
            $printer->text('NFC-e no '.$raw['numero'].' Serie '.($raw['serie'] ?? '')."\n");
        }

        if (! empty($raw['protocolo_autorizacao'])) {
            $printer->text("Protocolo de autorizacao:\n{$raw['protocolo_autorizacao']}\n");
        }

        if (! empty($raw['data_autorizacao'])) {
            $printer->text('Autorizada em: '.$raw['data_autorizacao']."\n");
        }

        if ($note->access_key) {
            $printer->feed();
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("Chave de acesso\n");
            $printer->text(chunk_split($note->access_key, 4, ' ')."\n");
            $printer->feed();
            $printer->qrCode($note->access_key, Printer::QR_ECLEVEL_L, 6);
            $printer->feed();
            $printer->text("Consulte pela Chave de Acesso em\nwww.nfce.fazenda.gov.br\n");
        }

        $printer->feed(2);
        $printer->cut();

        $data = $connector->getData();
        $printer->close();

        return $data;
    }

    public function buildClosingReceipt(array $report, ?Company $company = null): string
    {
        $connector = new MemoryPrintConnector;
        $printer = new Printer($connector);

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text($company?->name ?? config('app.name')."\n");
        $printer->setEmphasis(false);

        $printer->feed();
        $printer->setEmphasis(true);
        $printer->text("== FECHAMENTO DE PEDIDOS ==\n");
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $this->divider($printer);
        $printer->text('Data: '.$report['date']->format('d/m/Y')."\n");
        $printer->text('Emitido em: '.now()->format('d/m/Y H:i:s')."\n");

        foreach (['delivery' => 'DELIVERY', 'pdv' => 'PDV', 'geral' => 'GERAL'] as $key => $label) {
            $channel = $report[$key];

            $this->divider($printer);
            $printer->setEmphasis(true);
            $printer->text("{$label}\n");
            $printer->setEmphasis(false);

            $printer->text("Pedidos: {$channel['count']}\n");
            $printer->text("Cancelados/reembolsados: {$channel['cancelled_count']}\n");
            $printer->text('Descontos: R$ '.number_format((float) $channel['discounts'], 2, ',', '.')."\n");
            $printer->text('Dinheiro: R$ '.number_format((float) $channel['payments']['cash'], 2, ',', '.')."\n");
            $printer->text('PIX: R$ '.number_format((float) $channel['payments']['pix'], 2, ',', '.')."\n");
            $printer->text('Cartao: R$ '.number_format((float) $channel['payments']['card'], 2, ',', '.')."\n");

            $printer->setEmphasis(true);
            $printer->text("TOTAL {$label}: R$ ".number_format((float) $channel['revenue'], 2, ',', '.')."\n");
            $printer->setEmphasis(false);
        }

        $this->divider($printer);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text('Fim do fechamento de pedidos - '.$report['date']->format('d/m/Y')."\n");

        $printer->feed(2);
        $printer->cut();

        $data = $connector->getData();
        $printer->close();

        return $data;
    }

    private function divider(Printer $printer): void
    {
        $printer->text(str_repeat('-', 32)."\n");
    }

    private function printTotals(Printer $printer, Order $order): void
    {
        $printer->text('Subtotal: R$ '.number_format((float) $order->subtotal, 2, ',', '.')."\n");

        if ((float) $order->delivery_fee > 0) {
            $printer->text('Frete: R$ '.number_format((float) $order->delivery_fee, 2, ',', '.')."\n");
        }

        if ((float) $order->service_fee > 0) {
            $printer->text('Taxa de servico: R$ '.number_format((float) $order->service_fee, 2, ',', '.')."\n");
        }

        if ((float) $order->couvert_fee > 0) {
            $printer->text('Couvert artistico: R$ '.number_format((float) $order->couvert_fee, 2, ',', '.')."\n");
        }

        if ((float) $order->discount > 0) {
            $couponLabel = $order->coupon ? ' ('.$order->coupon->code.')' : '';
            $printer->text("Desconto{$couponLabel}: - R$ ".number_format((float) $order->discount, 2, ',', '.')."\n");
        }

        if ((float) ($order->manual_discount ?? 0) > 0) {
            $printer->text('Desconto operador: - R$ '.number_format((float) $order->manual_discount, 2, ',', '.')."\n");
        }

        $printer->setEmphasis(true);
        $printer->text('TOTAL: R$ '.number_format((float) $order->total, 2, ',', '.')."\n");
        $printer->setEmphasis(false);
    }

    private function printPayment(Printer $printer, Order $order): void
    {
        if ($order->payment_method === 'split') {
            $printer->text("Pagamento: Dividido\n");

            foreach ($order->payments as $partPayment) {
                $partLabel = match ($partPayment->payment_gateway) {
                    'cash' => 'Dinheiro',
                    'card_machine' => 'Cartao',
                    'pix_manual' => 'PIX',
                    default => $partPayment->payment_gateway,
                };

                $printer->text("  {$partLabel}: R$ ".number_format((float) $partPayment->amount, 2, ',', '.')."\n");
            }

            if ((float) $order->cash_received > 0) {
                $printer->text('Recebido: R$ '.number_format((float) $order->cash_received, 2, ',', '.')."\n");
                $printer->text('Troco: R$ '.number_format((float) ($order->cash_change ?? 0), 2, ',', '.')."\n");
            }
        } else {
            $method = match (true) {
                $order->payment_method === 'pix' => 'PIX',
                in_array($order->payment_method, ['card', 'credit_card'], true) => 'Cartao',
                $order->payment_method === 'cash' => 'Dinheiro',
                default => $order->payment_method,
            };

            $printer->text("Pagamento: {$method}\n");

            if ($order->payment_method === 'cash' && (float) $order->cash_received > 0) {
                $printer->text('Recebido: R$ '.number_format((float) $order->cash_received, 2, ',', '.')."\n");
                $printer->text('Troco: R$ '.number_format((float) ($order->cash_change ?? 0), 2, ',', '.')."\n");
            }
        }

        $isPaid = $order->payment?->status === 'paid';
        $printer->setEmphasis(true);
        $printer->text($isPaid ? "Status: PAGO\n" : "Status: NAO PAGO\n");
        $printer->setEmphasis(false);
    }

    private function printCustomerAndAddress(Printer $printer, Order $order): void
    {
        $isGuestCustomer = $order->customer?->phone === 'pdv-guest';

        if (! $isGuestCustomer && $order->customer) {
            $printer->setEmphasis(true);
            $printer->text($order->customer->name."\n");
            $printer->setEmphasis(false);
            $printer->text($order->customer->phone."\n");
        }

        if ($order->delivery_address_id) {
            $printer->text("ENDERECO DE ENTREGA\n");
            $printer->text($order->delivery_address.($order->delivery_number ? ', '.$order->delivery_number : '')."\n");

            if ($order->delivery_complement) {
                $printer->text($order->delivery_complement."\n");
            }

            $printer->text($order->delivery_neighborhood.($order->delivery_city ? ' - '.$order->delivery_city : '')."\n");
        }
    }
}
