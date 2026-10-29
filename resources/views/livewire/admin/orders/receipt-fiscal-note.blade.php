<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>DANFE NFC-e {{ $order->order_number }}</title>
    @php
        $raw = $note->raw_response;
        $issuerCnpj = preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', preg_replace('/\D/', '', $note->issuer_cnpj ?: ($order->branch->cnpj ?: ($company?->owner_cpf_cnpj ?? ''))));
        $accessKey = preg_replace('/\D/', '', (string) $note->access_key);
        $consumerDocument = $note->data['customer_cpf_cnpj'] ?? null;
        $authorizedAt = filled($raw['data_autorizacao'] ?? null)
            ? rescue(fn () => \Illuminate\Support\Carbon::parse($raw['data_autorizacao'])->format('d/m/Y H:i:s'), $raw['data_autorizacao'], false)
            : null;
        $paymentLabel = match ($order->payment_method) {
            'pix' => 'PIX',
            'card', 'credit_card' => 'Cartao',
            'cash' => 'Dinheiro',
            'split' => 'Dividido',
            default => $order->payment_method,
        };
    @endphp
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: DejaVu Sans Mono, monospace; font-size: 9px; color: #000; background: #fff; padding: 10px 10px; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .sm { font-size: 8px; }
        .divider { border-top: 1px dashed #000; margin: 5px 0; }
        .row { width: 100%; border-collapse: collapse; margin: 1px 0; }
        .row td { padding: 1px 0; vertical-align: top; }
        .total-label { font-weight: bold; font-size: 11px; padding-top: 3px; }
        .total-value { font-weight: bold; font-size: 11px; text-align: right; padding-top: 3px; }
        .warning { text-align: center; font-weight: bold; border: 1px solid #000; padding: 3px; margin: 4px 0; }
        .key { word-break: break-all; }
    </style>
</head>
<body>

    {{-- Emitente --}}
    <div class="center bold" style="font-size: 11px;">{{ $company?->name ?? config('app.name') }}</div>
    @if ($issuerCnpj)
        <div class="center sm">CNPJ: {{ $issuerCnpj }}</div>
    @endif
    @if ($order->branch->address)
        <div class="center sm">
            {{ $order->branch->address }}{{ $order->branch->number ? ', '.$order->branch->number : '' }}
            {{ $order->branch->neighborhood ? ' - '.$order->branch->neighborhood : '' }}{{ $order->branch->city ? ' - '.$order->branch->city : '' }}{{ $order->branch->state ? '/'.$order->branch->state : '' }}
        </div>
    @endif

    <div class="divider"></div>
    <div class="center bold">DANFE NFC-e</div>
    <div class="center sm">Documento Auxiliar da Nota Fiscal de Consumidor Eletronica</div>

    @if ($note->environment === 'homologacao')
        <div class="warning">EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL</div>
    @endif

    <div class="divider"></div>

    {{-- Itens --}}
    @foreach ($order->items as $item)
        <table class="row">
            <tr>
                <td style="width: 65%;" class="bold">{{ $item->quantity }}x {{ $item->product_name }}</td>
                <td class="right bold">R$ {{ number_format($item->subtotal, 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td colspan="2" class="sm" style="padding-left: 8px;">R$ {{ number_format($item->unit_price, 2, ',', '.') }} / un.</td>
            </tr>
        </table>
    @endforeach

    <div class="divider"></div>

    {{-- Totais --}}
    <table class="row">
        <tr>
            <td>Qtd. total de itens</td>
            <td class="right">{{ $order->items->sum('quantity') }}</td>
        </tr>
        <tr>
            <td>Subtotal</td>
            <td class="right">R$ {{ number_format($order->subtotal, 2, ',', '.') }}</td>
        </tr>
        @if ($order->delivery_fee > 0)
            <tr>
                <td>Frete</td>
                <td class="right">R$ {{ number_format($order->delivery_fee, 2, ',', '.') }}</td>
            </tr>
        @endif
        @if (($order->service_fee + $order->couvert_fee) > 0)
            <tr>
                <td>Acrescimos</td>
                <td class="right">R$ {{ number_format($order->service_fee + $order->couvert_fee, 2, ',', '.') }}</td>
            </tr>
        @endif
        @if (($order->discount + ($order->manual_discount ?? 0)) > 0)
            <tr>
                <td>Descontos</td>
                <td class="right">- R$ {{ number_format($order->discount + ($order->manual_discount ?? 0), 2, ',', '.') }}</td>
            </tr>
        @endif
        <tr>
            <td class="total-label">VALOR A PAGAR</td>
            <td class="total-value">R$ {{ number_format($order->total, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Forma de pagamento</td>
            <td class="right">{{ $paymentLabel }}</td>
        </tr>
    </table>

    <div class="divider"></div>

    {{-- Consulta e chave --}}
    <div class="center sm">Consulte pela Chave de Acesso em</div>
    <div class="center sm key">{{ $raw['url_consulta_nf'] ?? 'www.nfce.fazenda.gov.br' }}</div>
    @if ($accessKey)
        <div class="center sm key" style="margin-top: 2px;">{{ chunk_split($accessKey, 4, ' ') }}</div>
    @endif

    <div class="divider"></div>

    {{-- Consumidor --}}
    <div class="center sm">
        @if ($consumerDocument)
            CONSUMIDOR CPF/CNPJ: {{ $consumerDocument }}
        @else
            CONSUMIDOR NAO IDENTIFICADO
        @endif
    </div>

    <div class="divider"></div>

    {{-- Identificação da nota --}}
    <div class="center sm bold">
        NFC-e n. {{ $note->number ?? ($raw['numero'] ?? '-') }} Serie {{ $note->series ?? ($raw['serie'] ?? '-') }}
    </div>
    @if ($note->protocol ?? ($raw['protocolo'] ?? null))
        <div class="center sm">Protocolo de autorizacao: {{ $note->protocol ?? $raw['protocolo'] }}</div>
    @endif
    @if ($authorizedAt)
        <div class="center sm">Data de autorizacao: {{ $authorizedAt }}</div>
    @endif

    @if ($qrCode)
        <div class="center" style="margin-top: 6px; padding-bottom: 8px;">
            <img src="{{ $qrCode }}" style="width: 120px; height: 120px;" alt="QR Code NFC-e">
        </div>
    @endif

</body>
</html>
