<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedido recebido</title>
</head>
<body style="font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 0; color: #333;">
@php
    $primary      = $company?->primary_color      ?? '#B91C1C';
    $primaryDark  = $company?->primary_color_dark  ?? '#991B1B';
    $primaryLight = $company?->primary_color_light ?? '#DC2626';
    $secondary    = $company?->secondary_color     ?? '#D97706';
    $logoUrl      = $company?->logo_url;
    $companyName  = $company?->name ?? config('app.name');
@endphp

<div style="max-width: 600px; margin: 40px auto; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,.10);">

    {{-- Header --}}
    <div style="background: linear-gradient(135deg, {{ $primaryDark }} 0%, {{ $primary }} 60%, {{ $primaryLight }} 100%); padding: 32px 40px; text-align: center;">
        @if($logoUrl)
            <img src="{{ $logoUrl }}" alt="{{ $companyName }}" style="height: 56px; width: 56px; border-radius: 50%; object-fit: cover; border: 3px solid rgba(255,255,255,0.4); margin-bottom: 12px; display: block; margin-left: auto; margin-right: auto;">
        @endif
        <h1 style="color: #fff; margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.3px;">{{ $companyName }}</h1>
        <p style="color: rgba(255,255,255,0.75); margin: 4px 0 0; font-size: 13px;">Confirmação de Pedido</p>
    </div>

    {{-- Body --}}
    <div style="padding: 32px 40px;">
        <h2 style="font-size: 20px; margin-top: 0; color: #111;">Obrigado pelo seu pedido, {{ $customer->name }}! 🎉</h2>
        <p style="font-size: 15px; line-height: 1.6; color: #555;">
            Recebemos o seu pedido com sucesso. Assim que o pagamento for confirmado, começaremos a preparar tudo para você.
        </p>

        {{-- Order info box --}}
        <div style="background: #f9f9f9; border: 1px solid #e5e5e5; border-left: 4px solid {{ $primary }}; border-radius: 6px; padding: 20px 24px; margin: 24px 0;">
            <p style="margin: 0 0 8px; font-size: 14px;"><span style="color: #888;">Número do pedido:</span> &nbsp;<strong style="color: #111;">#{{ $order->order_number }}</strong></p>
            <p style="margin: 0 0 8px; font-size: 14px;"><span style="color: #888;">Data:</span> &nbsp;{{ $order->created_at->format('d/m/Y H:i') }}</p>
            @if($order->payment_method)
            <p style="margin: 0; font-size: 14px;"><span style="color: #888;">Pagamento:</span> &nbsp;{{ Str::upper($order->payment_method) }}</p>
            @endif
        </div>

        {{-- Items table --}}
        @if($order->relationLoaded('items') && $order->items->isNotEmpty())
        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
            <thead>
                <tr style="background: #f5f5f5;">
                    <th style="text-align: left; padding: 10px 8px; color: #555; border-bottom: 2px solid #e5e5e5;">Item</th>
                    <th style="text-align: center; padding: 10px 8px; color: #555; border-bottom: 2px solid #e5e5e5;">Qtd</th>
                    <th style="text-align: right; padding: 10px 8px; color: #555; border-bottom: 2px solid #e5e5e5;">Preço</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->items as $item)
                <tr>
                    <td style="padding: 9px 8px; border-bottom: 1px solid #f0f0f0;">{{ $item->product_name ?? $item->name }}</td>
                    <td style="text-align: center; padding: 9px 8px; border-bottom: 1px solid #f0f0f0;">{{ $item->quantity }}</td>
                    <td style="text-align: right; padding: 9px 8px; border-bottom: 1px solid #f0f0f0;">R$ {{ number_format($item->unit_price * $item->quantity, 2, ',', '.') }}</td>
                </tr>
                @endforeach
                <tr>
                    <td colspan="2" style="padding: 14px 8px 4px; font-weight: 700; font-size: 15px; color: #111;">Total</td>
                    <td style="text-align: right; padding: 14px 8px 4px; font-weight: 800; font-size: 17px; color: {{ $primary }};">R$ {{ number_format($order->total, 2, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>
        @else
        <p style="font-size: 15px;"><strong>Total:</strong> <span style="color: {{ $primary }}; font-weight: 800;">R$ {{ number_format($order->total, 2, ',', '.') }}</span></p>
        @endif
    </div>

    {{-- Footer --}}
    <div style="padding: 20px 40px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #f0f0f0; background: #fafafa;">
        <p style="margin: 0 0 4px;">Dúvidas? Entre em contato conosco.</p>
        <p style="margin: 0;">&copy; {{ now()->year }} {{ $companyName }}. Todos os direitos reservados.</p>
    </div>

</div>
</body>
</html>
