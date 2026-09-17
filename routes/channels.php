<?php

use App\Models\Order;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('wallet.{companyId}', function ($user, $companyId) {
    if (! $user) {
        return false;
    }

    $company = \App\Models\Company::withoutGlobalScopes()->find($companyId);

    // A tela /admin/wallet já é restrita a company_admin (ver routes/web.php);
    // o canal precisa da mesma restrição, não basta ter vínculo com a empresa.
    return $company && $user->canManageClosing($company);
});

// Painel admin (equipe da empresa): atualizações de pedido em tempo real
// (Kanban, sino de notificação, auto-print PDV). Sem guest — só staff logado.
Broadcast::channel('orders.{companyId}', function ($user, $companyId) {
    return $user && $user->companies()->where('companies.id', (int) $companyId)->exists();
});

// Painel admin (Orders\Show, impressão automática de nota fiscal no PDV) —
// carrega itens/total/access_key da nota fiscal, dados sensíveis o bastante
// pra não caber num canal público. O chat público do cliente (sem User
// autenticado — Laravel bloqueia canal privado pra convidado antes até de
// chamar este authorizer) continua recebendo só OrderStatusUpdated, que
// também é publicado num Channel público separado com payload mínimo
// (status + order_number) — ver App\Events\OrderStatusUpdated::broadcastOn().
Broadcast::channel('order.{orderId}', function ($user, $orderId) {
    $order = Order::withoutGlobalScopes()->find($orderId);

    return $order && $user->companies()->where('companies.id', $order->company_id)->exists();
});
