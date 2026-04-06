<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Pedidos</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; background: #fff; padding: 24px; }
        h1 { font-size: 18px; font-weight: bold; color: #1a1a1a; margin-bottom: 4px; }
        .subtitle { font-size: 11px; color: #6b7280; margin-bottom: 20px; }
        .summary { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        .summary td { width: 25%; padding: 0 8px 0 0; vertical-align: top; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; }
        .card-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af; margin-bottom: 4px; }
        .card-value { font-size: 16px; font-weight: bold; }
        .card-value.amber  { color: #d97706; }
        .card-value.green  { color: #059669; }
        .card-value.blue   { color: #2563eb; }
        .card-value.dark   { color: #111827; }
        table.orders { width: 100%; border-collapse: collapse; }
        table.orders thead tr { background: #f9fafb; border-bottom: 2px solid #e5e7eb; }
        table.orders th { padding: 8px 10px; text-align: left; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af; }
        table.orders th.right { text-align: right; }
        table.orders td { padding: 7px 10px; border-bottom: 1px solid #f3f4f6; font-size: 10px; color: #374151; }
        table.orders td.right { text-align: right; font-weight: 600; }
        table.orders td.mono  { font-family: DejaVu Sans Mono, monospace; font-size: 10px; color: #111827; font-weight: 600; }
        table.orders tr:nth-child(even) td { background: #fafafa; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 9px; font-weight: 600; }
        .badge-green  { background: #d1fae5; color: #065f46; }
        .badge-blue   { background: #dbeafe; color: #1e40af; }
        .badge-red    { background: #fee2e2; color: #991b1b; }
        .badge-yellow { background: #fef3c7; color: #92400e; }
        .badge-gray   { background: #f3f4f6; color: #374151; }
        .footer { margin-top: 20px; font-size: 9px; color: #9ca3af; text-align: right; }
    </style>
</head>
<body>

    <h1>Relatório de Pedidos</h1>
    <p class="subtitle">
        {{ $company?->name ?? config('app.name') }}
        &nbsp;·&nbsp; Gerado em {{ now()->format('d/m/Y \à\s H:i') }}
    </p>

    @php
        $totalCount    = $orders->count();
        $totalRevenue  = $orders->sum('total');
        $averageTicket = $totalCount > 0 ? $totalRevenue / $totalCount : 0;
        $topPayment    = $orders->isNotEmpty()
            ? $orders->groupBy('payment_method')->map->count()->sortDesc()->keys()->first()
            : null;
        $topLabel = match(strtolower($topPayment ?? '')) {
            'pix'  => 'PIX',
            'card' => 'Cartão',
            'cash' => 'Dinheiro',
            default => $topPayment ?? '—',
        };
    @endphp

    <table class="summary">
        <tr>
            <td><div class="card"><div class="card-label">Total de Pedidos</div><div class="card-value amber">{{ $totalCount }}</div></div></td>
            <td><div class="card"><div class="card-label">Receita Total</div><div class="card-value green">R$ {{ number_format($totalRevenue, 2, ',', '.') }}</div></div></td>
            <td><div class="card"><div class="card-label">Ticket Médio</div><div class="card-value blue">R$ {{ number_format($averageTicket, 2, ',', '.') }}</div></div></td>
            <td style="padding-right:0"><div class="card"><div class="card-label">Principal Pagamento</div><div class="card-value dark">{{ $topLabel }}</div></div></td>
        </tr>
    </table>

    <table class="orders">
        <thead>
            <tr>
                <th>Número</th>
                <th>Cliente</th>
                <th>Filial</th>
                <th>Data</th>
                <th>Pagamento</th>
                <th>Tipo</th>
                <th>Status</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($orders as $order)
                @php
                    $statusClass = match(true) {
                        in_array($order->status, ['paid', 'delivered'])  => 'badge-green',
                        in_array($order->status, ['preparing', 'ready']) => 'badge-blue',
                        $order->status === 'cancelled'                   => 'badge-red',
                        $order->status === 'awaiting_payment'            => 'badge-yellow',
                        default                                          => 'badge-gray',
                    };
                    $payLabel = match(strtolower($order->payment_method)) {
                        'pix'  => 'PIX',
                        'card' => 'Cartão',
                        'cash' => 'Dinheiro',
                        default => $order->payment_method,
                    };
                @endphp
                <tr>
                    <td class="mono">{{ $order->order_number }}</td>
                    <td>{{ $order->customer->name ?? '—' }}</td>
                    <td>{{ $order->branch->name ?? '—' }}</td>
                    <td>{{ $order->created_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $payLabel }}</td>
                    <td>{{ $order->order_type === 'delivery' ? 'Entrega' : 'Retirada' }}</td>
                    <td><span class="badge {{ $statusClass }}">{{ $order->status_label }}</span></td>
                    <td class="right">R$ {{ number_format($order->total, 2, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;padding:20px;color:#9ca3af;">Nenhum pedido no período.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="footer">
        Total: {{ $totalCount }} pedido(s) &nbsp;·&nbsp; Receita: R$ {{ number_format($totalRevenue, 2, ',', '.') }}
    </p>

</body>
</html>
