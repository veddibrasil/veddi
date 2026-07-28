<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Cupom {{ strtoupper($station) }} {{ $order->order_number }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: DejaVu Sans Mono, monospace; font-size: 10px; color: #1a0025; background: #fff; padding: 10px 10px; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .sm { font-size: 9px; }
        .muted { color: #8b5a9e; }
        .divider { border-top: 1px dashed #c06eec; margin: 5px 0; }
        .type-banner { text-align: center; font-weight: bold; font-size: 14px; letter-spacing: 2px; padding: 6px 0; margin-bottom: 5px; background-color: {{ ['cozinha' => '#c2410c', 'bar' => '#1d4ed8', 'entrega' => '#15803d'][$station] }}; color: #ffffff; }
        .order-number { font-weight: bold; }
        .item-name { font-weight: bold; font-size: 12px; }
        .item-block { margin: 6px 0; }
        .address-bar { margin-top: 4px; border-left: 3px solid #15803d; padding-left: 5px; }
        .total-label { font-weight: bold; font-size: 12px; }
        .total-value { font-weight: bold; font-size: 12px; text-align: right; }
    </style>
</head>
<body>

    <div class="center bold" style="font-size: 13px;">{{ $company?->name ?? config('app.name') }}</div>
    <div class="center muted">{{ $order->branch->name }}</div>

    <div class="divider"></div>

    <div class="type-banner">{{ ['cozinha' => 'COZINHA', 'bar' => 'BAR', 'entrega' => 'ENTREGA'][$station] }}</div>

    <table style="width: 100%;">
        <tr>
            <td>Pedido</td>
            <td style="text-align: right;" class="order-number">{{ $order->order_number }}</td>
        </tr>
        @if ($order->table_label)
            <tr>
                <td>Mesa/Comanda</td>
                <td style="text-align: right;">{{ $order->table_label }}</td>
            </tr>
        @endif
        <tr>
            <td>Hora</td>
            <td style="text-align: right;">{{ $order->created_at->format('d/m/Y H:i') }}</td>
        </tr>
    </table>

    @if ($station === 'entrega')
        <div class="divider"></div>

        @php $isGuestCustomer = $order->customer?->phone === 'pdv-guest'; @endphp
        @if (! $isGuestCustomer)
            <div class="bold">{{ $order->customer->name }}</div>
            <div class="sm muted">{{ $order->customer->phone }}</div>
        @endif

        <div class="address-bar">
            <div class="bold" style="font-size: 9px; letter-spacing: 1px;">ENDERECO DE ENTREGA</div>
            <div style="margin-top: 1px;">
                {{ $order->delivery_address }}{{ $order->delivery_number ? ', '.$order->delivery_number : '' }}
            </div>
            @if ($order->delivery_complement)
                <div>{{ $order->delivery_complement }}</div>
            @endif
            <div>
                {{ $order->delivery_neighborhood }}{{ $order->delivery_city ? ' - '.$order->delivery_city : '' }}
            </div>
        </div>
    @endif

    <div class="divider"></div>

    @forelse ($items as $item)
        <div class="item-block">
            <div class="item-name">{{ $item->quantity }}x {{ $item->product_name }}</div>
            @foreach ($item->options ?? [] as $group)
                @if (!empty($group['selections']))
                    <div class="sm" style="padding-left: 8px;">
                        <span class="bold">{{ $group['group_name'] ?? 'Opcoes' }}:</span>
                        @foreach ($group['selections'] as $sel)
                            <br>&nbsp;&nbsp;&bull; {{ $sel['qty'] }}x {{ $sel['name'] }}
                        @endforeach
                    </div>
                @endif
            @endforeach
        </div>
    @empty
        <div class="center muted" style="padding: 10px 0;">Nenhum item desta estação neste pedido.</div>
    @endforelse

    <div class="divider"></div>

    @if ($station === 'entrega')
        <table style="width: 100%;">
            <tr>
                <td>Pagamento</td>
                <td style="text-align: right;" class="bold">
                    @if ($order->payment_method === 'pix') PIX
                    @elseif (in_array($order->payment_method, ['card', 'credit_card'])) Cartao
                    @elseif ($order->payment_method === 'cash') Dinheiro
                    @else {{ $order->payment_method }}
                    @endif
                </td>
            </tr>
            @if ($order->payment_method === 'cash' && ! $order->payment)
                <tr>
                    <td class="total-label">COBRAR</td>
                    <td class="total-value">R$ {{ number_format($order->total, 2, ',', '.') }}</td>
                </tr>
            @endif
        </table>

        <div class="divider"></div>
    @endif

    @if ($order->notes)
        <div class="sm"><span class="bold">Obs: </span>{{ $order->notes }}</div>
        <div class="divider"></div>
    @endif

</body>
</html>
