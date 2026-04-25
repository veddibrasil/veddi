<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Cupom {{ $order->order_number }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: DejaVu Sans Mono, monospace; font-size: 10px; color: #111; background: #fff; padding: 14px 12px; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .sm { font-size: 9px; }
        .muted { color: #777; }
        .divider { border-top: 1px dashed #555; margin: 7px 0; }
        .row { width: 100%; border-collapse: collapse; margin: 2px 0; }
        .row td { padding: 1px 0; vertical-align: top; }
        .item-qty { font-size: 9px; color: #555; padding-bottom: 3px; }
        .total-label { font-weight: bold; font-size: 12px; padding-top: 4px; }
        .total-value { font-weight: bold; font-size: 12px; text-align: right; padding-top: 4px; }
    </style>
</head>
<body>

    {{-- Cabeçalho --}}
    <div class="center bold" style="font-size: 13px;">{{ $company?->name ?? config('app.name') }}</div>
    <div class="center" style="margin-top: 2px;">{{ $order->branch->name }}</div>
    @if ($order->branch->address)
        <div class="center sm muted">{{ $order->branch->address }}</div>
    @endif

    <div class="divider"></div>

    {{-- Informações do pedido --}}
    <table class="row">
        <tr>
            <td>Pedido</td>
            <td class="right bold">{{ $order->order_number }}</td>
        </tr>
        <tr>
            <td>Data</td>
            <td class="right">{{ $order->created_at->format('d/m/Y H:i') }}</td>
        </tr>
        <tr>
            <td>Tipo</td>
            <td class="right">{{ $order->order_type === 'delivery' ? 'Entrega' : 'Retirada' }}</td>
        </tr>
    </table>

    <div class="divider"></div>

    {{-- Itens --}}
    @foreach ($order->items as $item)
        <table class="row">
            <tr>
                <td style="width: 65%;">{{ $item->product_name }}</td>
                <td class="right">R$ {{ number_format($item->subtotal, 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td colspan="2" class="item-qty">
                    {{ $item->quantity }}x R$ {{ number_format($item->unit_price, 2, ',', '.') }}
                </td>
            </tr>
        </table>
    @endforeach

    <div class="divider"></div>

    {{-- Totais --}}
    <table class="row">
        <tr>
            <td>Subtotal</td>
            <td class="right">R$ {{ number_format($order->subtotal, 2, ',', '.') }}</td>
        </tr>
        @if ($order->delivery_fee > 0)
            <tr>
                <td>Frete</td>
                <td class="right">R$ {{ number_format($order->delivery_fee, 2, ',', '.') }}</td>
            </tr>
        @elseif ($order->order_type === 'delivery')
            <tr>
                <td>Frete</td>
                <td class="right">Gratis</td>
            </tr>
        @endif
        @if ($order->discount > 0)
            <tr>
                <td>Desconto{{ $order->coupon ? ' ('.$order->coupon->code.')' : '' }}</td>
                <td class="right">- R$ {{ number_format($order->discount, 2, ',', '.') }}</td>
            </tr>
        @endif
        <tr style="border-top: 1px solid #ccc;">
            <td class="total-label">TOTAL</td>
            <td class="total-value">R$ {{ number_format($order->total, 2, ',', '.') }}</td>
        </tr>
    </table>

    <div class="divider"></div>

    {{-- Pagamento --}}
    <table class="row">
        <tr>
            <td>Pagamento</td>
            <td class="right bold">
                @if ($order->payment_method === 'PIX') PIX
                @elseif ($order->payment_method === 'CARD') Cartao
                @elseif ($order->payment_method === 'CASH') Dinheiro
                @else {{ $order->payment_method }}
                @endif
            </td>
        </tr>
        @if ($order->payment?->status === 'paid' && $order->payment->paid_at)
            <tr>
                <td class="sm muted">Pago em</td>
                <td class="right sm muted">{{ $order->payment->paid_at->format('d/m/Y H:i') }}</td>
            </tr>
        @endif
    </table>

    <div class="divider"></div>

    {{-- Cliente --}}
    <div class="bold">{{ $order->customer->name }}</div>
    <div class="sm muted">{{ $order->customer->phone }}</div>
    @if ($order->order_type === 'delivery')
        <div class="sm" style="margin-top: 3px;">
            {{ $order->customer->address }}{{ $order->customer->number ? ', '.$order->customer->number : '' }}
        </div>
        @if ($order->customer->complement)
            <div class="sm">{{ $order->customer->complement }}</div>
        @endif
        <div class="sm">
            {{ $order->customer->neighborhood }}{{ $order->customer->city ? ' - '.$order->customer->city : '' }}
        </div>
    @endif

    @if ($order->notes)
        <div class="divider"></div>
        <div class="sm"><span class="bold">Obs: </span>{{ $order->notes }}</div>
    @endif

    <div class="divider"></div>

    <div class="center muted" style="font-size: 8px;">Obrigado pela preferencia!</div>

</body>
</html>
