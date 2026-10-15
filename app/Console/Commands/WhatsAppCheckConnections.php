<?php

namespace App\Console\Commands;

use App\Exceptions\WhatsAppApiException;
use App\Models\WhatsAppConnection;
use App\Services\Messaging\WhatsAppConnectionMonitor;
use App\Services\Messaging\WhatsAppOnboardingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WhatsAppCheckConnections extends Command
{
    protected $signature = 'whatsapp:check-connections';

    protected $description = 'Avisa os restaurantes (sino do painel e e-mail) sobre WhatsApp inativo, com erro ou com qualidade baixa.';

    public function handle(WhatsAppConnectionMonitor $monitor, WhatsAppOnboardingService $onboarding): int
    {
        $this->refreshQuality($onboarding);

        $summary = $monitor->run();

        $this->info(sprintf(
            '%d conexão(ões) verificada(s), %d restaurante(s) avisado(s), %d alerta(s) enviado(s).',
            $summary['checked'],
            $summary['alerted'],
            $summary['alerts'],
        ));

        return self::SUCCESS;
    }

    /**
     * O webhook phone_number_quality_update não traz mais a qualidade do número: sem consultar a
     * Graph API, o alerta quality_red só dispararia depois de uma sincronização manual.
     * Best effort: falha de uma conexão não impede o monitoramento das demais.
     */
    private function refreshQuality(WhatsAppOnboardingService $onboarding): void
    {
        WhatsAppConnection::withoutGlobalScopes()
            ->where('status', WhatsAppConnection::STATUS_ACTIVE)
            ->whereNotNull('access_token')
            ->whereNotNull('phone_number_id')
            ->orderBy('id')
            ->each(function (WhatsAppConnection $connection) use ($onboarding) {
                try {
                    $onboarding->refreshPhoneInfo($connection);
                } catch (WhatsAppApiException $e) {
                    Log::channel('whatsapp')->warning('Monitoramento: não foi possível atualizar a qualidade do número', [
                        'connection_id' => $connection->id,
                        'code' => $e->getCode(),
                    ]);
                }
            });
    }
}
