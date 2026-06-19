<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Fechamento de Caixa #{{ $report['session']->id }}</title>
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
        .negative { color: #c0392b; }
        .footer { text-align: center; font-size: 8px; color: #9B10C8; }
        .section-title { font-weight: bold; font-size: 9px; letter-spacing: 1px; color: #7A00A3; margin-top: 2px; }
    </style>
</head>
<body>
    @php $session = $report['session']; @endphp

    {{-- Cabeçalho --}}
    <div class="center bold brand" style="font-size: 13px;">{{ $company?->name ?? config('app.name') }}</div>
    <div class="center muted" style="margin-top: 2px;">{{ $session->branch->name }}</div>

    <div class="divider"></div>

    <div class="type-banner">FECHAMENTO DE CAIXA</div>

    <table class="row">
        <tr>
            <td>Terminal</td>
            <td class="right">{{ $session->terminal_name ?? '—' }}</td>
        </tr>
        <tr>
            <td>Operador</td>
            <td class="right">{{ $session->user->name ?? '—' }}</td>
        </tr>
        <tr>
            <td>Abertura</td>
            <td class="right">{{ $session->created_at->format('d/m/Y H:i:s') }}</td>
        </tr>
        <tr>
            <td>Fechamento</td>
            <td class="right">{{ $session->closed_at->format('d/m/Y H:i:s') }}</td>
        </tr>
    </table>

    <div class="divider"></div>

    {{-- Saldo --}}
    <table class="row">
        <tr>
            <td>Abertura</td>
            <td class="right">R$ {{ number_format($report['opening'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Entrada (dinheiro)</td>
            <td class="right">R$ {{ number_format($report['cash_sales'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Suprimentos</td>
            <td class="right">R$ {{ number_format($report['supplies'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Retiradas</td>
            <td class="right negative">- R$ {{ number_format($report['withdrawals'], 2, ',', '.') }}</td>
        </tr>
        <tr style="border-top: 1px solid #dca8f5;">
            <td class="total-label">SALDO ESPERADO</td>
            <td class="total-value">R$ {{ number_format($report['expected'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Saldo contado</td>
            <td class="right">R$ {{ number_format($report['closing'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="bold {{ $report['difference'] < 0 ? 'negative' : '' }}">Diferença</td>
            <td class="right bold {{ $report['difference'] < 0 ? 'negative' : '' }}">R$ {{ number_format($report['difference'], 2, ',', '.') }}</td>
        </tr>
    </table>

    @if ($session->reconciliation_notes)
        <div class="sm" style="margin-top: 3px;"><span class="bold">Obs:</span> {{ $session->reconciliation_notes }}</div>
    @endif

    <div class="divider"></div>

    {{-- Vendas por forma de pagamento --}}
    <div class="section-title">VENDAS POR FORMA DE PAGAMENTO</div>
    <table class="row">
        <tr>
            <td>Dinheiro</td>
            <td class="right">R$ {{ number_format($report['payments']['cash'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>PIX</td>
            <td class="right">R$ {{ number_format($report['payments']['pix'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Cartão</td>
            <td class="right">R$ {{ number_format($report['payments']['credit_card'], 2, ',', '.') }}</td>
        </tr>
        <tr style="border-top: 1px solid #dca8f5;">
            <td class="total-label">TOTAL VENDAS</td>
            <td class="total-value">R$ {{ number_format($report['revenue'], 2, ',', '.') }}</td>
        </tr>
    </table>

    <div class="divider"></div>

    <table class="row">
        <tr>
            <td>Pedidos</td>
            <td class="right">{{ $report['orders_count'] }}</td>
        </tr>
        <tr>
            <td>Cancelados/reembolsados</td>
            <td class="right">{{ $report['cancelled_count'] }}</td>
        </tr>
        <tr>
            <td>Descontos</td>
            <td class="right">R$ {{ number_format($report['discounts'], 2, ',', '.') }}</td>
        </tr>
    </table>

    @if ($report['movements']->isNotEmpty())
        <div class="divider"></div>
        <div class="section-title">HISTÓRICO DE MOVIMENTAÇÃO</div>
        <table class="row">
            @foreach ($report['movements'] as $movement)
                <tr>
                    <td class="sm">
                        {{ $movement->action === 'cash_supply' ? 'Suprimento' : 'Retirada' }}
                        @if ($movement->reason) <span class="muted">({{ $movement->reason }})</span> @endif
                    </td>
                    <td class="right sm {{ $movement->action === 'cash_withdrawal' ? 'negative' : '' }}">
                        {{ $movement->action === 'cash_withdrawal' ? '- ' : '' }}R$ {{ number_format((float) $movement->amount, 2, ',', '.') }}
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($report['top_products']->isNotEmpty())
        <div class="divider"></div>
        <div class="section-title">PRODUTOS MAIS VENDIDOS</div>
        <table class="row">
            @foreach ($report['top_products'] as $i => $product)
                <tr>
                    <td class="sm">{{ $i + 1 }}. {{ $product->product_name }}</td>
                    <td class="right sm">{{ (int) $product->total_qty }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="divider"></div>

    <div class="footer">Fim do fechamento de caixa — sessão #{{ $session->id }}</div>

</body>
</html>
