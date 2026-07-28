<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Fechamento de Pedidos {{ $report['date']->format('d/m/Y') }}</title>
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
        .total-label { font-weight: bold; font-size: 11px; padding-top: 3px; color: #7A00A3; }
        .total-value { font-weight: bold; font-size: 11px; text-align: right; padding-top: 3px; color: #7A00A3; }
        .type-banner { text-align: center; font-weight: bold; font-size: 11px; letter-spacing: 2px; padding: 5px 0; margin-bottom: 5px; background-color: #7A00A3; color: #ffffff; }
        .channel-banner { font-weight: bold; font-size: 10px; letter-spacing: 1px; padding: 3px 4px; margin: 6px 0 2px; background-color: #f1def8; color: #7A00A3; }
        .footer { text-align: center; font-size: 8px; color: #9B10C8; }
        .section-title { font-weight: bold; font-size: 9px; letter-spacing: 1px; color: #7A00A3; margin-top: 2px; }
    </style>
</head>
<body>
    {{-- Cabeçalho --}}
    <div class="center bold brand" style="font-size: 13px;">{{ $company?->name ?? config('app.name') }}</div>

    <div class="divider"></div>

    <div class="type-banner">FECHAMENTO DE PEDIDOS</div>

    <table class="row">
        <tr>
            <td>Data</td>
            <td class="right">{{ $report['date']->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td>Emitido em</td>
            <td class="right">{{ now()->format('d/m/Y H:i:s') }}</td>
        </tr>
    </table>

    @foreach (['delivery' => 'DELIVERY', 'pdv' => 'PDV', 'geral' => 'GERAL'] as $key => $label)
        @php $channel = $report[$key]; @endphp
        <div class="channel-banner">{{ $label }}</div>
        <table class="row">
            <tr>
                <td>Pedidos</td>
                <td class="right">{{ $channel['count'] }}</td>
            </tr>
            <tr>
                <td>Cancelados/reembolsados</td>
                <td class="right">{{ $channel['cancelled_count'] }}</td>
            </tr>
            <tr>
                <td>Descontos</td>
                <td class="right">R$ {{ number_format($channel['discounts'], 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td>Dinheiro</td>
                <td class="right">R$ {{ number_format($channel['payments']['cash'], 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td>PIX</td>
                <td class="right">R$ {{ number_format($channel['payments']['pix'], 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td>Cartão</td>
                <td class="right">R$ {{ number_format($channel['payments']['card'], 2, ',', '.') }}</td>
            </tr>
            <tr style="border-top: 1px solid #dca8f5;">
                <td class="total-label">TOTAL {{ $label }}</td>
                <td class="total-value">R$ {{ number_format($channel['revenue'], 2, ',', '.') }}</td>
            </tr>
        </table>
    @endforeach

    <div class="divider"></div>

    <div class="footer">Fim do fechamento de pedidos — {{ $report['date']->format('d/m/Y') }}</div>

</body>
</html>
