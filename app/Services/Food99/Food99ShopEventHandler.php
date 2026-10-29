<?php

namespace App\Services\Food99;

use App\Contracts\Food99EventHandler;
use App\Models\CompanyNotification;
use App\Models\Food99Integration;
use App\Models\Food99WebhookEvent;
use Illuminate\Support\Facades\Log;

/**
 * Callbacks da loja: vínculo/desvínculo (shopBindStatus), mudança de status (shopStatus) e
 * falha ao abrir automaticamente (autoOnlineResult).
 */
class Food99ShopEventHandler implements Food99EventHandler
{
    public const TYPES = ['shopBindStatus', 'shopStatus', 'autoOnlineResult'];

    /** sub_biz_status da 99 traduzido. */
    public const SUB_BIZ_STATUS_LABELS = [
        1 => 'Aberta',
        2 => 'Pausada',
        3 => 'Fechada',
        4 => 'App da loja desconectado',
        5 => 'Fora do horário',
        6 => 'Bloqueada pela 99Food',
        7 => 'Fechada pelo sistema (sem entregadores)',
    ];

    /** err_code do autoOnlineResult. */
    public const AUTO_ONLINE_ERRORS = [
        1000 => 'erro do sistema da 99Food',
        1001 => 'loja fora do horário de funcionamento',
        1002 => 'loja bloqueada ou sob análise da 99Food',
        1003 => 'cardápio vazio ou sem itens à venda',
        1004 => 'loja fechada manualmente (só reabre manualmente)',
        1005 => 'pendência de cadastro na 99Food',
    ];

    public function handles(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public function handle(Food99WebhookEvent $event): bool
    {
        return match ($event->event_type) {
            'shopBindStatus' => $this->bindStatus($event),
            'shopStatus' => $this->shopStatus($event),
            'autoOnlineResult' => $this->autoOnlineResult($event),
            default => false,
        };
    }

    /**
     * Desvínculo na 99 (pelo portal ou pela própria 99): a loja para de mandar pedidos e o
     * token deixa de valer — integração em erro e aviso pra reconectar. O vínculo novo é
     * concluído pela tela (escolha da loja), não aqui.
     */
    private function bindStatus(Food99WebhookEvent $event): bool
    {
        $data = $event->data();
        $appShopIds = array_map('strval', (array) ($data['appShopIDList'] ?? $data['app_shop_id_list'] ?? []));

        if (($data['bindStatus'] ?? null) !== 'unbind' || $appShopIds === []) {
            Log::channel('food99')->info('99Food: vínculo de loja recebido', ['lojas' => count($appShopIds)]);

            return $appShopIds !== [];
        }

        // Fora de request: sem escopo de empresa, cada integração casada pelo app_shop_id.
        $integrations = Food99Integration::withoutGlobalScopes()
            ->whereIn('app_shop_id', $appShopIds)
            ->whereIn('status', [Food99Integration::STATUS_ACTIVE, Food99Integration::STATUS_PAUSED])
            ->get();

        foreach ($integrations as $integration) {
            $integration->forceFill([
                'status' => Food99Integration::STATUS_ERROR,
                'auth_token' => null,
                'token_expires_at' => null,
            ])->save();

            CompanyNotification::create([
                'company_id' => $integration->company_id,
                'type' => 'food99_alert',
                'title' => '99Food: loja desvinculada',
                'subtitle' => 'A 99Food desfez o vínculo da loja com o VEDDI. Pedidos novos não chegam até reconectar.',
                'link' => url('/admin/settings/99food'),
            ]);

            Log::channel('food99')->warning('99Food: loja desvinculada pela 99', [
                'food99_integration_id' => $integration->id,
                'company_id' => $integration->company_id,
            ]);
        }

        return $integrations->isNotEmpty();
    }

    private function shopStatus(Food99WebhookEvent $event): bool
    {
        $integration = $event->food99Integration;
        if (! $integration) {
            return false;
        }

        $data = $event->data();
        $integration->forceFill(array_filter([
            'store_status' => isset($data['biz_status']) ? (int) $data['biz_status'] : null,
            'sub_biz_status' => isset($data['sub_biz_status']) ? (int) $data['sub_biz_status'] : null,
            'store_status_updated_at' => now(),
        ], fn ($value) => $value !== null))->save();

        return true;
    }

    private function autoOnlineResult(Food99WebhookEvent $event): bool
    {
        $integration = $event->food99Integration;
        $data = $event->data();

        if (! $integration || in_array($data['result'] ?? null, [true, 1, '1', 'success'], true)) {
            return $integration !== null;
        }

        $code = (int) ($data['err_code'] ?? 0);
        $reason = self::AUTO_ONLINE_ERRORS[$code] ?? ($data['reason'] ?? 'motivo não informado');

        CompanyNotification::create([
            'company_id' => $integration->company_id,
            'type' => 'food99_alert',
            'title' => '99Food: a loja não abriu automaticamente',
            'subtitle' => mb_substr("Motivo: {$reason}. Confira a loja na 99Food.", 0, 255),
            'link' => url('/admin/settings/99food'),
        ]);

        return true;
    }
}
