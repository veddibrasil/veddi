<?php

namespace App\Services\Food99;

use App\Mail\Food99ConnectionAlert;
use App\Models\CompanyNotification;
use App\Models\Food99Integration;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisa o restaurante (sino e, quando ele precisa agir, e-mail aos administradores) de problemas
 * na 99Food que impedem os pedidos de chegarem ao painel, e mantém a loja fechada na 99 enquanto
 * a empresa não pode operar (bloqueada ou pendente de pagamento).
 *
 * Mesmo controle de repetição do IfoodConnectionMonitor: alerts_sent guarda quando cada alerta
 * saiu, o lembrete volta depois de REMINDER_HOURS e o registro some quando o problema acaba.
 */
class Food99ConnectionMonitor
{
    public const ALERT_CONNECTION_LOST = 'connection_lost';

    public const ALERT_WEBHOOK_SILENT = 'webhook_silent';

    public const ALERT_APP_DISCONNECTED = 'app_disconnected';

    public const ALERT_BLOCKED = 'blocked_by_99';

    /** Marca interna (não é aviso): loja fechada na 99 porque a empresa não pode operar. */
    public const PRESENCE_CLOSED = 'presence_closed';

    /** Loja aberta e nenhum callback (nem de status) nesse tempo: provável falha de entrega. */
    public const WEBHOOK_SILENT_HOURS = 6;

    public const REMINDER_HOURS = 24;

    public function __construct(private readonly Food99StoreStatusSync $store) {}

    /** @return array{checked: int, alerted: int, alerts: int} */
    public function run(): array
    {
        $summary = ['checked' => 0, 'alerted' => 0, 'alerts' => 0];

        Food99Integration::withoutGlobalScopes()
            ->with(['company', 'branch'])
            ->whereNotNull('shop_id')
            ->whereIn('status', [Food99Integration::STATUS_ACTIVE, Food99Integration::STATUS_PAUSED, Food99Integration::STATUS_ERROR])
            ->lazyById()
            ->each(function (Food99Integration $integration) use (&$summary) {
                $summary['checked']++;
                $this->syncPresence($integration);

                $sent = $this->process($integration);
                if ($sent > 0) {
                    $summary['alerted']++;
                    $summary['alerts'] += $sent;
                }
            });

        return $summary;
    }

    /** Avalia uma integração e envia os alertas devidos. Devolve quantos foram enviados. */
    public function process(Food99Integration $integration): int
    {
        if (! $integration->company) {
            return 0;
        }

        $conditions = $this->conditionsFor($integration);
        $previous = (array) ($integration->alerts_sent ?? []);
        $marker = array_intersect_key($previous, [self::PRESENCE_CLOSED => true]);
        $alerts = array_diff_key($previous, $marker);

        $due = array_filter($conditions, fn (array $alert, string $key) => $this->isDue($alerts[$key] ?? null), ARRAY_FILTER_USE_BOTH);
        $registered = array_intersect_key($alerts, $conditions);
        foreach (array_keys($due) as $key) {
            $registered[$key] = Date::now()->toIso8601String();
        }

        if ($due !== []) {
            $this->notify($integration, array_values($due));
        }

        $this->saveAlerts($integration, $registered + $marker);

        return count($due);
    }

    /** @return array<string, array{title: string, message: string, email: bool}> */
    public function conditionsFor(Food99Integration $integration): array
    {
        $branch = $integration->branch?->name;
        $store = $branch ? "da filial {$branch}" : 'da sua loja';
        $company = $integration->company;
        $companyOperates = $company !== null && ($company->isActive() || $company->isOverdue()) && $company->canUseMarketplaceIntegrations();
        $conditions = [];

        if (! $companyOperates) {
            return [];
        }

        if ($integration->status === Food99Integration::STATUS_ERROR) {
            $conditions[self::ALERT_CONNECTION_LOST] = [
                'title' => "A conexão com a 99Food {$store} caiu",
                'message' => 'A 99Food desfez o vínculo da loja ou o acesso não pôde ser renovado. Até reconectar em Integrações > 99Food, os pedidos novos não chegam ao painel.',
                'email' => true,
            ];
        }

        if ($integration->status !== Food99Integration::STATUS_ACTIVE) {
            return $conditions;
        }

        if ($integration->sub_biz_status === 4) {
            $conditions[self::ALERT_APP_DISCONNECTED] = [
                'title' => "A loja {$store} está offline na 99Food",
                'message' => 'A 99Food indica que o app da loja está desconectado. Abra o app 99Food Loja ou confira a loja no portal da 99Food.',
                'email' => true,
            ];
        }

        if ($integration->sub_biz_status === 6) {
            $conditions[self::ALERT_BLOCKED] = [
                'title' => "A loja {$store} foi bloqueada pela 99Food",
                'message' => 'A 99Food bloqueou a loja. Fale com o atendimento da 99Food para regularizar.',
                'email' => true,
            ];
        }

        if ($integration->isStoreOpen() && $integration->sub_biz_status === 1 && $integration->isWebhookSilent(self::WEBHOOK_SILENT_HOURS)) {
            $conditions[self::ALERT_WEBHOOK_SILENT] = [
                'title' => "Pedidos da 99Food {$store} podem não estar chegando",
                'message' => 'A loja está aberta na 99Food, mas não recebemos nenhum aviso dela nas últimas horas. Acompanhe pelo app da 99Food; se continuar, fale com o suporte.',
                'email' => false,
            ];
        }

        return $conditions;
    }

    /**
     * Empresa bloqueada ou sem o módulo: fecha a loja na 99 (ninguém veria o pedido). Ao voltar a
     * operar, desfaz o fechamento. Suspensa pelo restaurante já foi fechada pela tela.
     */
    private function syncPresence(Food99Integration $integration): void
    {
        $company = $integration->company;
        if (! $company || $integration->status === Food99Integration::STATUS_ERROR) {
            return;
        }

        $operates = ($company->isActive() || $company->isOverdue()) && $company->canUseMarketplaceIntegrations();
        $alerts = (array) ($integration->alerts_sent ?? []);
        $closedByUs = isset($alerts[self::PRESENCE_CLOSED]);

        try {
            if (! $operates && ! $closedByUs) {
                $this->store->close($integration);
                $alerts[self::PRESENCE_CLOSED] = Date::now()->toIso8601String();
                $this->saveAlerts($integration, $alerts);
            } elseif ($operates && $closedByUs) {
                $this->store->revokeClose($integration);
                unset($alerts[self::PRESENCE_CLOSED]);
                $this->saveAlerts($integration, $alerts);
            }
        } catch (Throwable $e) {
            Log::channel('food99')->warning('Monitor 99Food: não foi possível ajustar a loja à situação da empresa', [
                'food99_integration_id' => $integration->id,
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function saveAlerts(Food99Integration $integration, array $alerts): void
    {
        if ($alerts === (array) ($integration->alerts_sent ?? [])) {
            return;
        }

        Food99Integration::withoutGlobalScopes()->whereKey($integration->id)
            ->update(['alerts_sent' => $alerts === [] ? null : json_encode($alerts)]);
        $integration->alerts_sent = $alerts === [] ? null : $alerts;
    }

    private function isDue(?string $lastSentAt): bool
    {
        return $lastSentAt === null || Date::parse($lastSentAt)->lte(Date::now()->subHours(self::REMINDER_HOURS));
    }

    /** @param  array<int, array{title: string, message: string, email: bool}>  $alerts */
    private function notify(Food99Integration $integration, array $alerts): void
    {
        $company = $integration->company;

        foreach ($alerts as $alert) {
            CompanyNotification::create([
                'company_id' => $company->id,
                'type' => 'food99_alert',
                'title' => mb_substr($alert['title'], 0, 255),
                'subtitle' => mb_substr($alert['message'], 0, 255),
                'link' => route('admin.settings.99food'),
            ]);
        }

        $emailAlerts = array_values(array_filter($alerts, fn (array $alert) => $alert['email']));
        $admins = $emailAlerts === [] ? collect() : $company->users()->wherePivot('role', 'company_admin')->get();

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->queue(new Food99ConnectionAlert($admin, $company, $emailAlerts));
            } catch (Throwable $e) {
                Log::channel('food99')->warning('Monitor 99Food: falha ao enfileirar o e-mail de alerta', [
                    'company_id' => $company->id,
                    'food99_integration_id' => $integration->id,
                    'user_id' => $admin->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::channel('food99')->warning('Monitor 99Food: restaurante avisado', [
            'company_id' => $company->id,
            'food99_integration_id' => $integration->id,
            'alerts' => count($alerts),
            'emails' => $admins->count(),
        ]);
    }
}
