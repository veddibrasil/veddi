<?php

require_once __DIR__.'/flows_helpers.php';

use App\Models\CompanyNotification;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * AUDITORIA FLUXOS — plano Free acima de 50 pedidos/mês: OrderService::createOrder() cria uma
 * CompanyNotification "Limite de pedidos do plano gratuito ultrapassado" a CADA pedido novo,
 * sem deduplicar (51º, 52º, 53º... cada um gera um sino). Além disso o aviso usa
 * Company::isWithinOrderLimit() (conta pedidos não cancelados, inclusive pendentes/não pagos)
 * enquanto a taxa realmente aplicada no split usa feePercentageForOrder() (só pedidos pagos):
 * o aviso "taxa ajustada de 1% para 3%" pode aparecer sem a taxa ter mudado.
 */
test('aviso de limite do plano Free é criado uma vez no mês, não a cada pedido', function () {
    $ctx = flowAuditContext(['plan' => 'free'], stock: null);

    // 50 pedidos já pagos no mês.
    for ($i = 0; $i < 50; $i++) {
        Order::withoutGlobalScopes()->create([
            'company_id' => $ctx['company']->id,
            'branch_id' => $ctx['branch']->id,
            'customer_id' => $ctx['customer']->id,
            'subtotal' => 10, 'total' => 10,
            'status' => 'delivered',
            'payment_method' => 'pix',
            'order_type' => 'delivery',
        ]);
    }

    flowAuditChatOrder($ctx, qty: 1);
    flowAuditChatOrder($ctx, qty: 1);
    flowAuditChatOrder($ctx, qty: 1);

    // Hoje: 3 notificações iguais.
    expect(CompanyNotification::withoutGlobalScopes()
        ->where('company_id', $ctx['company']->id)
        ->where('type', 'plan_limit')
        ->count())->toBe(1);
});
