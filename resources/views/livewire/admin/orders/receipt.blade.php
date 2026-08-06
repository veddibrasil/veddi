<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Cupom {{ $order->order_number }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: DejaVu Sans Mono, monospace; font-size: 9px; color: #1a0025; background: #fff; padding: 10px 10px; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .sm { font-size: 8px; }
        .muted { color: #8b5a9e; }
        .brand { color: #7A00A3; }
        .divider { border-top: 1px dashed #c06eec; margin: 5px 0; }
        .row { width: 100%; border-collapse: collapse; margin: 1px 0; }
        .row td { padding: 1px 0; vertical-align: top; }
        .item-qty { font-size: 8px; color: #8b5a9e; padding-bottom: 2px; }
        .total-label { font-weight: bold; font-size: 11px; padding-top: 3px; color: #7A00A3; }
        .total-value { font-weight: bold; font-size: 11px; text-align: right; padding-top: 3px; color: #7A00A3; }
        .type-banner { text-align: center; font-weight: bold; font-size: 11px; letter-spacing: 2px; padding: 5px 0; margin-bottom: 5px; background-color: #7A00A3; color: #ffffff; }
        .order-number { color: #7A00A3; font-weight: bold; }
        .troco { color: #7A00A3; font-weight: bold; }
        .footer { text-align: center; font-size: 8px; color: #9B10C8; }
        .option-price { color: #7A00A3; }
        .address-bar { margin-top: 4px; border-left: 3px solid #7A00A3; padding-left: 5px; }
    </style>
</head>
<body>

    {{-- Cabeçalho --}}
    <div class="center bold brand" style="font-size: 13px;">{{ $company?->name ?? config('app.name') }}</div>
    <div class="center muted" style="margin-top: 2px;">{{ $order->branch->name }}</div>
    @if ($order->branch->address)
        <div class="center sm muted">{{ $order->branch->address }}</div>
    @endif

    <div class="divider"></div>

    {{-- Tipo de pedido em destaque --}}
    @if ($order->order_type === 'pdv')
        <div class="type-banner">BALCAO / PDV</div>
    @elseif ($order->order_type === 'delivery')
        <div class="type-banner">ENTREGA</div>
    @else
        <div class="type-banner">RETIRADA NO LOCAL</div>
    @endif

    {{-- Informações do pedido --}}
    <table class="row">
        <tr>
            <td>Pedido</td>
            <td class="right order-number">{{ $order->order_number }}</td>
        </tr>
        <tr>
            <td>Data</td>
            <td class="right">{{ $order->created_at->format('d/m/Y H:i') }}</td>
        </tr>
    </table>

    <div class="divider"></div>

    {{-- Itens --}}
    @foreach ($order->items as $item)
        <table class="row">
            <tr>
                <td style="width: 65%; font-weight: bold; font-size: 10px;">
                    {{ $item->quantity }}x {{ $item->product_name }}
                </td>
                <td class="right bold" style="font-size: 10px;">R$ {{ number_format($item->subtotal, 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td colspan="2" class="item-qty" style="padding-left: 8px; color: #555;">
                    R$ {{ number_format($item->unit_price, 2, ',', '.') }} / un.
                </td>
            </tr>
            @foreach ($item->options ?? [] as $group)
                @if (!empty($group['selections']))
                    <tr>
                        <td colspan="2" style="padding-left: 8px; padding-top: 1px; font-size: 8px; color: #444;">
                            <span style="font-weight: bold;">{{ $group['group_name'] ?? 'Opcoes' }}:</span>
                            @foreach ($group['selections'] as $sel)
                                <br>&nbsp;&nbsp;&bull; {{ $sel['qty'] }}x {{ $sel['name'] }}@if ((float) ($sel['additional_price'] ?? 0) > 0) <span class="option-price">(+R$ {{ number_format((float) $sel['additional_price'], 2, ',', '.') }})</span>@endif
                            @endforeach
                        </td>
                    </tr>
                @endif
            @endforeach
        </table>
        <div style="border-top: 1px dotted #dca8f5; margin: 3px 0;"></div>
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
        @elseif ($order->order_type === 'delivery' || $order->delivery_address_id)
            <tr>
                <td>Frete</td>
                <td class="right">Gratis</td>
            </tr>
        @endif
        @if ($order->service_fee > 0)
            <tr>
                <td>Taxa de servico</td>
                <td class="right">R$ {{ number_format($order->service_fee, 2, ',', '.') }}</td>
            </tr>
        @endif
        @if ($order->couvert_fee > 0)
            <tr>
                <td>Couvert artistico</td>
                <td class="right">R$ {{ number_format($order->couvert_fee, 2, ',', '.') }}</td>
            </tr>
        @endif
        @if ($order->discount > 0)
            <tr>
                <td>Desconto{{ $order->coupon ? ' ('.$order->coupon->code.')' : '' }}</td>
                <td class="right">- R$ {{ number_format($order->discount, 2, ',', '.') }}</td>
            </tr>
        @endif
        @if (($order->manual_discount ?? 0) > 0)
            <tr>
                <td>Desconto operador</td>
                <td class="right">- R$ {{ number_format($order->manual_discount, 2, ',', '.') }}</td>
            </tr>
        @endif
        <tr style="border-top: 1px solid #dca8f5;">
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
                @if ($order->payment_method === 'pix') PIX
                @elseif (in_array($order->payment_method, ['card', 'credit_card'])) Cartao
                @elseif ($order->payment_method === 'cash') Dinheiro
                @elseif ($order->payment_method === 'split') Dividido
                @else {{ $order->payment_method }}
                @endif
            </td>
        </tr>
        @if ($order->payment_method === 'split')
            @foreach ($order->payments as $partPayment)
                <tr>
                    <td class="sm">
                        @if ($partPayment->payment_gateway === 'cash') Dinheiro
                        @elseif ($partPayment->payment_gateway === 'card_machine') Cartao
                        @elseif ($partPayment->payment_gateway === 'pix_manual') PIX
                        @else {{ $partPayment->payment_gateway }}
                        @endif
                    </td>
                    <td class="right sm">R$ {{ number_format($partPayment->amount, 2, ',', '.') }}</td>
                </tr>
            @endforeach
        @endif
        @if (in_array($order->payment_method, ['cash', 'split']) && $order->cash_received > 0)
            <tr>
                <td class="sm">Recebido</td>
                <td class="right sm">R$ {{ number_format($order->cash_received, 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="sm troco">Troco</td>
                <td class="right sm troco">R$ {{ number_format($order->cash_change ?? 0, 2, ',', '.') }}</td>
            </tr>
        @endif
        @if ($order->payment?->status === 'paid' && $order->payment->paid_at)
            <tr>
                <td class="sm muted">Pago em</td>
                <td class="right sm muted">{{ $order->payment->paid_at->format('d/m/Y H:i') }}</td>
            </tr>
        @endif
    </table>

    <div class="divider"></div>

    {{-- Cliente --}}
    @php $isGuestCustomer = $order->customer->phone === 'pdv-guest'; @endphp
    @if (!$isGuestCustomer)
        <div class="bold">{{ $order->customer->name }}</div>
        <div class="sm muted">{{ $order->customer->phone }}</div>
    @else
        <div class="muted sm">Cliente balcao</div>
    @endif
    @if ($order->delivery_address_id)
        <div class="address-bar">
            <div class="bold" style="font-size: 8px; letter-spacing: 1px;">ENDERECO DE ENTREGA</div>
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

    @if ($order->notes)
        <div class="divider"></div>
        <div class="sm"><span class="bold">Obs: </span>{{ $order->notes }}</div>
    @endif

    @if ($company?->canUseFiscalNotes() && $order->activeFiscalNote)
        <div class="divider"></div>
        <div class="center sm">
            @if ($order->activeFiscalNote->status === 'authorized')
                <div class="bold brand">NFC-e autorizada</div>
                <div class="muted" style="word-break: break-all;">{{ chunk_split($order->activeFiscalNote->access_key, 4, ' ') }}</div>
            @else
                <div class="muted">NFC-e em processamento</div>
            @endif
        </div>
    @endif

    <div class="divider"></div>

    <div class="footer">Obrigado pela preferencia!</div>

</body>
</html>
