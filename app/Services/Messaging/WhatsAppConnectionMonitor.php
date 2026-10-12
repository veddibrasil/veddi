<?php

namespace App\Services\Messaging;

use App\Mail\WhatsAppConnectionAlert;
use App\Models\Company;
use App\Models\CompanyNotification;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Monitoramento diário das conexões de WhatsApp dos restaurantes. Avisa o restaurante (sino do
 * painel + e-mail aos administradores) de problemas que ele mesmo precisa resolver:
 *
 * - app_inactive: coexistência com o app WhatsApp Business sem uso — a Meta desconecta em ~14 dias;
 * - error: conexão que já funcionou e parou (token revogado, conta desativada...);
 * - quality_red: qualidade do número vermelha — a Meta pode limitar ou bloquear os envios.
 *
 * Um alerta não se repete todo dia: fica registrado em alerts_sent, volta a ser enviado como
 * lembrete depois de REMINDER_DAYS enquanto o problema durar, e o registro some quando o problema
 * é resolvido (um novo problema do mesmo tipo avisa de novo).
 */
class WhatsAppConnectionMonitor
{
    public const ALERT_APP_INACTIVE = 'app_inactive';

    public const ALERT_ERROR = 'error';

    public const ALERT_QUALITY_RED = 'quality_red';

    /** A Meta desconecta a coexistência após ~14 dias sem uso do app: avisa com margem de dois dias. */
    public const INACTIVITY_DAYS = 12;

    public const REMINDER_DAYS = 7;

    /**
     * @return array{checked: int, alerted: int, alerts: int}
     */
    public function run(): array
    {
        $summary = ['checked' => 0, 'alerted' => 0, 'alerts' => 0];

        WhatsAppConnection::withoutGlobalScopes()
            ->with('company')
            ->whereIn('status', [WhatsAppConnection::STATUS_ACTIVE, WhatsAppConnection::STATUS_ERROR])
            ->orderBy('id')
            ->each(function (WhatsAppConnection $connection) use (&$summary) {
                $summary['checked']++;

                $sent = $this->process($connection);

                if ($sent > 0) {
                    $summary['alerted']++;
                    $summary['alerts'] += $sent;
                }
            });

        return $summary;
    }

    /** Avalia uma conexão e envia os alertas devidos. Devolve quantos alertas foram enviados. */
    public function process(WhatsAppConnection $connection): int
    {
        $company = $connection->company;

        if (! $company) {
            return 0;
        }

        $conditions = $this->conditionsFor($connection);
        $previous = (array) ($connection->alerts_sent ?? []);

        $due = array_filter(
            $conditions,
            fn (array $alert, string $key) => $this->isDue($previous[$key] ?? null),
            ARRAY_FILTER_USE_BOTH,
        );

        // Só os problemas que ainda existem permanecem registrados; os resolvidos saem daqui.
        $registered = array_intersect_key($previous, $conditions);

        foreach (array_keys($due) as $key) {
            $registered[$key] = Date::now()->toIso8601String();
        }

        if ($due !== []) {
            $this->notify($connection, $company, array_values($due));
        }

        if ($registered !== $previous) {
            WhatsAppConnection::withoutGlobalScopes()
                ->whereKey($connection->id)
                ->update(['alerts_sent' => $registered === [] ? null : json_encode($registered)]);
        }

        return count($due);
    }

    /**
     * Problemas atuais da conexão, por tipo.
     *
     * @return array<string, array{title: string, message: string}>
     */
    public function conditionsFor(WhatsAppConnection $connection): array
    {
        $conditions = [];

        // Erro durante o onboarding (connected_at nulo) o restaurante já viu na tela ao conectar.
        if ($connection->status === WhatsAppConnection::STATUS_ERROR && $connection->connected_at !== null) {
            $conditions[self::ALERT_ERROR] = [
                'title' => 'O WhatsApp da sua loja parou de funcionar',
                'message' => trim(($connection->last_error ?: 'A conexão com o WhatsApp foi interrompida.').' Enquanto isso, seus clientes não recebem avisos dos pedidos. Reconecte o número em Configurações > WhatsApp.'),
            ];
        }

        if ($connection->status === WhatsAppConnection::STATUS_ACTIVE) {
            $idleDays = $this->appIdleDays($connection);

            if ($idleDays !== null && $idleDays >= self::INACTIVITY_DAYS) {
                $conditions[self::ALERT_APP_INACTIVE] = [
                    'title' => 'Abra o app WhatsApp Business para manter a conexão',
                    'message' => "O app WhatsApp Business está sem uso há {$idleDays} dias. A Meta desconecta a integração depois de cerca de 14 dias sem uso do app. Abra o app no celular do número conectado e use-o normalmente para evitar a desconexão.",
                ];
            }

            if ($connection->quality_rating === 'RED') {
                $conditions[self::ALERT_QUALITY_RED] = [
                    'title' => 'A qualidade do seu número de WhatsApp está baixa',
                    'message' => 'A Meta classificou a qualidade do número como baixa (vermelha), geralmente por clientes que bloqueiam ou denunciam as mensagens. Se continuar assim, a Meta pode limitar ou bloquear os envios. Confira em Configurações > WhatsApp se os clientes estão aceitando receber os avisos.',
                ];
            }
        }

        return $conditions;
    }

    /** Dias sem uso do app na coexistência (a partir do último eco ou, sem nenhum, da conexão). */
    private function appIdleDays(WhatsAppConnection $connection): ?int
    {
        if (! $connection->isCoexistence()) {
            return null;
        }

        $reference = $connection->last_app_activity_at ?? $connection->connected_at;

        return $reference === null ? null : (int) floor($reference->diffInDays(Date::now()));
    }

    private function isDue(?string $lastSentAt): bool
    {
        if ($lastSentAt === null) {
            return true;
        }

        return Date::parse($lastSentAt)->lte(Date::now()->subDays(self::REMINDER_DAYS));
    }

    /** @param  array<int, array{title: string, message: string}>  $alerts */
    private function notify(WhatsAppConnection $connection, Company $company, array $alerts): void
    {
        foreach ($alerts as $alert) {
            CompanyNotification::create([
                'company_id' => $company->id,
                'type' => 'whatsapp_alert',
                'title' => $alert['title'],
                'subtitle' => $alert['message'],
                'link' => route('admin.settings.whatsapp'),
            ]);
        }

        $admins = $company->users()->wherePivot('role', 'company_admin')->get();

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->queue(new WhatsAppConnectionAlert($admin, $company, $alerts));
            } catch (Throwable $e) {
                Log::channel('whatsapp')->warning('Monitor WhatsApp: falha ao enfileirar o e-mail de alerta', [
                    'company_id' => $company->id,
                    'connection_id' => $connection->id,
                    'user_id' => $admin->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::channel('whatsapp')->info('Monitor WhatsApp: restaurante avisado', [
            'company_id' => $company->id,
            'connection_id' => $connection->id,
            'alerts' => count($alerts),
            'emails' => $admins->count(),
        ]);
    }
}
