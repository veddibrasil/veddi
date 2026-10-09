<?php

namespace App\Services\Ifood;

use App\Mail\IfoodConnectionAlert;
use App\Models\CompanyNotification;
use App\Models\IfoodIntegration;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisa o restaurante (sino do painel e, quando ele precisa agir, e-mail aos administradores)
 * de problemas na conexão com o iFood que impedem os pedidos de chegarem ao painel:
 *
 * - reauthorization: o iFood encerrou a autorização da loja — só o restaurante resolve, reconectando;
 * - polling_stalled: a loja está ativa, mas o iFood não é consultado há mais de POLLING_STALE_MINUTES.
 *   Costuma ser instabilidade (nossa ou do iFood), então vai só pro sino e pro log, sem e-mail.
 *
 * Mesmo controle de repetição do WhatsAppConnectionMonitor: alerts_sent guarda quando cada alerta
 * saiu, o lembrete volta depois de REMINDER_HOURS e o registro some quando o problema acaba.
 */
class IfoodConnectionMonitor
{
    public const ALERT_REAUTHORIZATION = 'reauthorization';

    public const ALERT_POLLING_STALLED = 'polling_stalled';

    /** O polling roda a cada 30s; 10 minutos sem consulta já é falha, não atraso. */
    public const POLLING_STALE_MINUTES = 10;

    public const REMINDER_HOURS = 24;

    /**
     * @return array{checked: int, alerted: int, alerts: int}
     */
    public function run(): array
    {
        $summary = ['checked' => 0, 'alerted' => 0, 'alerts' => 0];

        IfoodIntegration::withoutGlobalScopes()
            ->with(['company', 'branch'])
            ->whereNotNull('merchant_id')
            ->whereIn('status', ['active', 'reauthorization_required'])
            ->orderBy('id')
            ->each(function (IfoodIntegration $integration) use (&$summary) {
                $summary['checked']++;

                $sent = $this->process($integration);

                if ($sent > 0) {
                    $summary['alerted']++;
                    $summary['alerts'] += $sent;
                }
            });

        return $summary;
    }

    /** Avalia uma integração e envia os alertas devidos. Devolve quantos alertas foram enviados. */
    public function process(IfoodIntegration $integration): int
    {
        $company = $integration->company;

        if (! $company) {
            return 0;
        }

        $conditions = $this->conditionsFor($integration);
        $previous = (array) ($integration->alerts_sent ?? []);

        $due = array_filter(
            $conditions,
            fn (array $alert, string $key) => $this->isDue($previous[$key] ?? null),
            ARRAY_FILTER_USE_BOTH,
        );

        $registered = array_intersect_key($previous, $conditions);

        foreach (array_keys($due) as $key) {
            $registered[$key] = Date::now()->toIso8601String();
        }

        if ($due !== []) {
            $this->notify($integration, array_values($due));
        }

        if ($registered !== $previous) {
            IfoodIntegration::withoutGlobalScopes()
                ->whereKey($integration->id)
                ->update(['alerts_sent' => $registered === [] ? null : json_encode($registered)]);
            $integration->alerts_sent = $registered === [] ? null : $registered;
        }

        return count($due);
    }

    /**
     * Problemas atuais da integração, por tipo.
     *
     * @return array<string, array{title: string, message: string, email: bool}>
     */
    public function conditionsFor(IfoodIntegration $integration): array
    {
        $branch = $integration->branch?->name;
        $store = $branch ? "da filial {$branch}" : 'da sua loja';
        $conditions = [];

        // Empresa bloqueada ou sem o módulo não recebe pedido do iFood: e-mail diário seria só ruído.
        $company = $integration->company;
        $companyOperates = $company !== null
            && ($company->isActive() || $company->isOverdue())
            && $company->canUseIfoodIntegration();

        if ($integration->status === 'reauthorization_required' && $companyOperates) {
            $conditions[self::ALERT_REAUTHORIZATION] = [
                'title' => "A conexão com o iFood {$store} caiu",
                'message' => 'O iFood encerrou a autorização desta loja. Até você reconectar em Integrações > iFood, os pedidos novos do iFood não chegam ao painel.',
                'email' => true,
            ];
        }

        if ($integration->isOperational() && $this->pollingStalled($integration)) {
            $conditions[self::ALERT_POLLING_STALLED] = [
                'title' => "Pedidos do iFood {$store} podem não estar chegando",
                'message' => 'Não conseguimos consultar o iFood nos últimos minutos. Acompanhe os pedidos pelo Gestor de Pedidos do iFood enquanto isso; se continuar, fale com o suporte.',
                'email' => false,
            ];
        }

        return $conditions;
    }

    private function pollingStalled(IfoodIntegration $integration): bool
    {
        $threshold = Date::now()->subMinutes(self::POLLING_STALE_MINUTES);

        // Conectar e retomar gravam last_polled_at como ponto de partida. updated_at não serve de
        // referência: o próprio alerts_sent e o KEEPALIVE do webhook o renovam a toda hora.
        return $integration->last_polled_at !== null && $integration->last_polled_at->lt($threshold);
    }

    private function isDue(?string $lastSentAt): bool
    {
        if ($lastSentAt === null) {
            return true;
        }

        return Date::parse($lastSentAt)->lte(Date::now()->subHours(self::REMINDER_HOURS));
    }

    /** @param  array<int, array{title: string, message: string, email: bool}>  $alerts */
    private function notify(IfoodIntegration $integration, array $alerts): void
    {
        $company = $integration->company;

        foreach ($alerts as $alert) {
            CompanyNotification::create([
                'company_id' => $company->id,
                'type' => 'ifood_alert',
                'title' => mb_substr($alert['title'], 0, 255),
                'subtitle' => mb_substr($alert['message'], 0, 255),
                'link' => route('admin.settings.ifood'),
            ]);
        }

        $emailAlerts = array_values(array_filter($alerts, fn (array $alert) => $alert['email']));
        $admins = $emailAlerts === [] ? collect() : $company->users()->wherePivot('role', 'company_admin')->get();

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->queue(new IfoodConnectionAlert($admin, $company, $emailAlerts));
            } catch (Throwable $e) {
                Log::channel('ifood')->warning('Monitor iFood: falha ao enfileirar o e-mail de alerta', [
                    'company_id' => $company->id,
                    'ifood_integration_id' => $integration->id,
                    'user_id' => $admin->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::channel('ifood')->warning('Monitor iFood: restaurante avisado', [
            'company_id' => $company->id,
            'ifood_integration_id' => $integration->id,
            'alerts' => count($alerts),
            'emails' => $admins->count(),
        ]);
    }
}
