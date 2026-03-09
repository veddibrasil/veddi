<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedido recebido</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 0; color: #333; }
        .container { max-width: 600px; margin: 40px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .header { background: #1a1a1a; padding: 32px 40px; text-align: center; }
        .header h1 { color: #fff; margin: 0; font-size: 22px; }
        .body { padding: 32px 40px; }
        .body h2 { font-size: 18px; margin-top: 0; }
        .order-box { background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 6px; padding: 20px; margin: 24px 0; }
        .order-box p { margin: 6px 0; font-size: 14px; }
        .order-box .label { color: #888; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 14px; }
        th { text-align: left; border-bottom: 2px solid #e5e5e5; padding: 8px 4px; color: #555; }
        td { padding: 8px 4px; border-bottom: 1px solid #f0f0f0; }
        .total-row td { font-weight: bold; border-bottom: none; padding-top: 12px; }
        .footer { padding: 24px 40px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #f0f0f0; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>{{ $company?->name ?? config('app.name') }}</h1>
    </div>

    <div class="body">
        <h2>Obrigado pelo seu pedido, {{ $customer->name }}!</h2>
        <p>Recebemos o seu pedido com sucesso. Assim que o pagamento for confirmado, começaremos a preparar tudo para você.</p>

        <div class="order-box">
            <p><span class="label">Número do pedido:</span> <strong>#{{ $order->order_number }}</strong></p>
            <p><span class="label">Data:</span> {{ $order->created_at->format('d/m/Y H:i') }}</p>
            @if($order->payment_method)
            <p><span class="label">Forma de pagamento:</span> {{ Str::upper($order->payment_method) }}</p>
            @endif
        </div>

        @if($order->relationLoaded('items') && $order->items->isNotEmpty())
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th style="text-align:center">Qtd</th>
                    <th style="text-align:right">Preço</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->items as $item)
                <tr>
                    <td>{{ $item->product_name ?? $item->name }}</td>
                    <td style="text-align:center">{{ $item->quantity }}</td>
                    <td style="text-align:right">R$ {{ number_format($item->unit_price * $item->quantity, 2, ',', '.') }}</td>
                </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="2">Total</td>
                    <td style="text-align:right">R$ {{ number_format($order->total, 2, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>
        @else
        <p><strong>Total:</strong> R$ {{ number_format($order->total, 2, ',', '.') }}</p>
        @endif
    </div>

    <div class="footer">
        <p>Dúvidas? Entre em contato conosco.</p>
        <p>&copy; {{ now()->year }} {{ $company?->name ?? config('app.name') }}. Todos os direitos reservados.</p>
    </div>
</div>
</body>
</html>
