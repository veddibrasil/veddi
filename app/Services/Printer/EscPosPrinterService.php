<?php

namespace App\Services\Printer;

use App\Contracts\PrinterServiceInterface;
use App\Models\BranchPrinter;
use App\Models\Company;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Support\Orders\OrderFulfillment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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

    public function buildOrderReceipt(Order $order, string $station, ?Company $company = null, ?Collection $itemsOverride = null): string
    {
        $connector = new MemoryPrintConnector;
        $printer = new Printer($connector);

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text(($company?->name ?? config('app.name'))."\n");
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

        $marketplace = $order->marketplaceDetails();

        if ($order->scheduled_at && ! $marketplace) {
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->setEmphasis(true);
            $printer->text('*** AGENDADO: '.$order->scheduled_at->setTimezone(config('app.timezone'))->format('d/m/Y H:i')." ***\n");
            $printer->setEmphasis(false);
            $printer->setJustification(Printer::JUSTIFY_LEFT);
        }

        $printer->text($marketplace ? "Pedido: {$marketplace->channelLabel()} #{$marketplace->displayId()} ({$order->order_number})\n" : "Pedido: {$order->order_number}\n");

        if ($order->table_label) {
            $printer->text("Mesa/Comanda: {$order->table_label}\n");
        }

        $printer->text('Data: '.$order->created_at->format('d/m/Y H:i')."\n");

        $isGeneralReceipt = $station === 'geral';

        if ($marketplace) {
            $this->divider($printer);
            foreach ($marketplace->receiptLines($station) as $line) {
                $printer->setEmphasis($line['bold']);
                $printer->text($line['text']."\n");
            }
            $printer->setEmphasis(false);
        }

        $this->divider($printer);

        $items = match (true) {
            $itemsOverride !== null => $itemsOverride,
            // geral/entrega sempre levam o pedido inteiro (cupom de cliente/balcão e
            // guia de entrega). cozinha/bar sempre filtram por categoria, pra cozinha
            // nunca imprimir item de bar (e vice-versa).
            in_array($station, ['geral', 'entrega'], true) => $order->items,
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

            if ($item->notes) {
                $printer->setEmphasis(true);
                $printer->text("  Obs: {$item->notes}\n");
                $printer->setEmphasis(false);
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
            // Pedido de marketplace: forma de pagamento, troco e valor a cobrar já saíram no bloco da plataforma.
            if (! $marketplace) {
                $this->divider($printer);
                $this->printPayment($printer, $order);
            }
            $this->divider($printer);
            $this->printCustomerAndAddress($printer, $order);
        }

        if ($order->notes) {
            $this->divider($printer);
            $printer->setEmphasis(true);
            $printer->text("Obs. do pedido: {$order->notes}\n");
            $printer->setEmphasis(false);
        }

        $handoffFooter = self::handoffFooterLines($order, $station);
        if ($handoffFooter !== []) {
            $this->divider($printer);
            $this->printFooterLines($printer, $handoffFooter);
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
        $printer->text(($company?->name ?? config('app.name'))."\n");
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
            // Focus devolve a chave com prefixo "NFe"; o QR oficial (qrcode_url) e o portal
            // de consulta (url_consulta_nf) são da SEFAZ da UF — só a chave não abre a nota.
            $accessKey = preg_replace('/\D/', '', $note->access_key);

            $printer->feed();
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("Chave de acesso\n");
            $printer->text(chunk_split($accessKey, 4, ' ')."\n");
            $printer->feed();
            $printer->qrCode($raw['qrcode_url'] ?? $accessKey, Printer::QR_ECLEVEL_L, 6);
            $printer->feed();
            $printer->text("Consulte pela Chave de Acesso em\n".($raw['url_consulta_nf'] ?? 'www.nfce.fazenda.gov.br')."\n");
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
        $printer->text(($company?->name ?? config('app.name'))."\n");
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
            if (($channel['payments']['split'] ?? 0) > 0) {
                $printer->text('Dividido: R$ '.number_format((float) $channel['payments']['split'], 2, ',', '.')."\n");
            }
            if (($channel['payments']['other'] ?? 0) > 0) {
                $printer->text('Outros: R$ '.number_format((float) $channel['payments']['other'], 2, ',', '.')."\n");
            }

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
            $printer->text(($order->marketplaceDetails() ? 'Cupom '.$order->marketplaceDetails()->channelLabel() : 'Desconto operador').': - R$ '.number_format((float) $order->manual_discount, 2, ',', '.')."\n");
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

    /**
     * Rodapé de quem solta o pedido: tipo, horário combinado, horas, cliente, endereço e
     * pagamento. Sai no cupom da cozinha quando o pedido é de entrega/retirada (a cozinha
     * despacha) e sempre no cupom de entrega. As mesmas linhas alimentam o térmico e o PDF
     * (receipt-station), pra papel nenhum discordar do outro.
     *
     * Marketplace: contato, código de coleta, pagamento e troco vêm do bloco da plataforma
     * (`receiptLines('entrega')`) sem as linhas que o cupom já imprimiu no topo; o resumo
     * genérico de pagamento fica de fora pra não repetir.
     *
     * @return array<int, array{text: string, bold: bool, center: bool, size: string, divider: bool}>
     */
    public static function handoffFooterLines(Order $order, string $station): array
    {
        $fulfillment = OrderFulfillment::for($order);

        $applies = match ($station) {
            'cozinha' => $fulfillment->isHandoff(),
            'entrega' => true,
            default => false,
        };

        if (! $applies) {
            return [];
        }

        $line = fn (string $text, bool $bold = false, bool $center = false, string $size = 'normal') => [
            'text' => $text, 'bold' => $bold, 'center' => $center, 'size' => $size, 'divider' => false,
        ];
        $divider = ['text' => '', 'bold' => false, 'center' => false, 'size' => 'normal', 'divider' => true];
        $money = fn (float $value) => OrderFulfillment::money($value);

        $timezone = config('app.timezone');
        $now = now()->setTimezone($timezone);
        $clock = fn (CarbonInterface $at) => $at->isSameDay($now) ? $at->format('H:i') : $at->format('d/m H:i');

        $lines = [$line('*** '.mb_strtoupper($fulfillment->label()).' ***', true, true, 'big')];

        if ($dueAt = $fulfillment->dueAt()) {
            $lines[] = $line('AGENDADO PARA '.$dueAt->format('d/m H:i'), true, true, 'tall');
        }

        $createdAt = $order->created_at?->copy()->setTimezone($timezone);
        $acceptedAt = $order->chat_accepted_at?->copy()->setTimezone($timezone);
        $times = array_filter([
            $createdAt ? 'Pedido '.$clock($createdAt) : null,
            $acceptedAt ? 'Aceito '.$clock($acceptedAt) : null,
        ]);
        if ($times !== []) {
            $lines[] = $line(implode(' | ', $times));
        }
        $lines[] = $line('Impresso '.$clock($now));

        $marketplace = $order->marketplaceDetails();
        $customer = $order->customer;
        $contact = [];

        if ($customer && $customer->phone !== 'pdv-guest') {
            $contact[] = $line((string) $customer->name, true);

            // Marketplace guarda um marcador no telefone; o contato sai no bloco da plataforma.
            if (! $marketplace && $customer->phone) {
                $contact[] = $line('Tel: '.$customer->phone);
            }
        }

        if ($fulfillment->kind() === OrderFulfillment::DELIVERY && $order->delivery_address_id) {
            $contact[] = $line(trim($order->delivery_address.($order->delivery_number ? ', '.$order->delivery_number : '')), true);

            if ($order->delivery_complement) {
                $contact[] = $line((string) $order->delivery_complement);
            }

            $district = trim(($order->delivery_neighborhood ?? '').($order->delivery_city ? ' - '.$order->delivery_city : ''), ' -');
            if ($district !== '') {
                $contact[] = $line($district);
            }
        }

        if ($contact !== []) {
            $lines = [...$lines, $divider, ...$contact];
        }

        if ($marketplace) {
            $alreadyPrinted = array_column($marketplace->receiptLines($station), 'text');
            $platformLines = array_values(array_filter(
                $marketplace->receiptLines('entrega'),
                fn (array $platformLine) => ! in_array($platformLine['text'], $alreadyPrinted, true),
            ));

            if ($platformLines !== []) {
                $lines[] = $divider;
                foreach ($platformLines as $platformLine) {
                    $lines[] = $line($platformLine['text'], $platformLine['bold']);
                }
            }
        }

        $lines[] = $divider;

        if (! $marketplace) {
            $lines[] = $line(mb_strtoupper(str_replace(' · ', ' - ', $fulfillment->paymentHeadline())), true);

            if ($changeLine = $fulfillment->changeLine()) {
                $lines[] = $line(mb_strtoupper($changeLine), true);
            }
        }

        if ((float) $order->delivery_fee > 0) {
            $lines[] = $line('Taxa de entrega: '.$money((float) $order->delivery_fee));
        }

        $lines[] = $line('TOTAL: '.$money((float) $order->total), true);

        return $lines;
    }

    /**
     * Térmico em ASCII, como os blocos de marketplace: o perfil padrão do escpos-php troca a
     * página de código sozinho, mas a numeração varia entre fabricantes e acento vira lixo.
     *
     * @param  array<int, array{text: string, bold: bool, center: bool, size: string, divider: bool}>  $lines
     */
    private function printFooterLines(Printer $printer, array $lines): void
    {
        foreach ($lines as $line) {
            if ($line['divider']) {
                $this->divider($printer);

                continue;
            }

            $printer->setJustification($line['center'] ? Printer::JUSTIFY_CENTER : Printer::JUSTIFY_LEFT);
            match ($line['size']) {
                'big' => $printer->setTextSize(2, 2),
                'tall' => $printer->setTextSize(1, 2),
                default => null,
            };
            $printer->setEmphasis($line['bold']);
            $printer->text(Str::ascii($line['text'])."\n");
            $printer->setEmphasis(false);

            if ($line['size'] !== 'normal') {
                $printer->setTextSize(1, 1);
            }
        }

        $printer->setJustification(Printer::JUSTIFY_LEFT);
    }

    private function printCustomerAndAddress(Printer $printer, Order $order): void
    {
        $isGuestCustomer = $order->customer?->phone === 'pdv-guest';

        if (! $isGuestCustomer && $order->customer) {
            $printer->setEmphasis(true);
            $printer->text($order->customer->name."\n");
            $printer->setEmphasis(false);

            // Cadastro de marketplace guarda um marcador interno no telefone; o contato
            // (0800/virtual + localizador) já saiu no bloco da plataforma.
            if (! $order->isMarketplaceOrder()) {
                $printer->text($order->customer->phone."\n");
            }
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
