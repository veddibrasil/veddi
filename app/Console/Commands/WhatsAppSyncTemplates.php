<?php

namespace App\Console\Commands;

use App\Concerns\FindsCompanyByOption;
use App\Exceptions\WhatsAppApiException;
use App\Models\WhatsAppConnection;
use App\Services\Messaging\WhatsAppOnboardingService;
use App\Services\Messaging\WhatsAppTemplateProvisioner;
use Illuminate\Console\Command;

class WhatsAppSyncTemplates extends Command
{
    use FindsCompanyByOption;

    protected $signature = 'whatsapp:sync-templates
        {--company= : ID ou slug da empresa. Sem isso, sincroniza as conexões em provisionamento, aguardando templates ou ativas}';

    protected $description = 'Reexecuta o provisionamento dos templates de WhatsApp e sincroniza o status com a Meta (suporte).';

    public function handle(WhatsAppTemplateProvisioner $provisioner, WhatsAppOnboardingService $onboarding): int
    {
        $query = WhatsAppConnection::withoutGlobalScopes()
            ->whereNotNull('access_token')
            ->whereNotNull('waba_id');

        if (filled($this->option('company'))) {
            $company = $this->findCompany((string) $this->option('company'));

            if (! $company) {
                $this->error("Empresa \"{$this->option('company')}\" não encontrada.");

                return self::FAILURE;
            }

            $query->where('company_id', $company->id);
        } else {
            $query->whereIn('status', [
                WhatsAppConnection::STATUS_PROVISIONING,
                WhatsAppConnection::STATUS_TEMPLATES_PENDING,
                WhatsAppConnection::STATUS_ACTIVE,
            ]);
        }

        $connections = $query->orderBy('id')->get();

        if ($connections->isEmpty()) {
            $this->warn('Nenhuma conexão de WhatsApp com token para sincronizar.');

            return filled($this->option('company')) ? self::FAILURE : self::SUCCESS;
        }

        $exitCode = self::SUCCESS;

        foreach ($connections as $connection) {
            try {
                $result = $provisioner->provision($connection);
            } catch (WhatsAppApiException $e) {
                $this->error("Conexão #{$connection->id} (empresa {$connection->company_id}): falha temporária na Meta ({$e->getCode()}). Tente de novo.");
                $exitCode = self::FAILURE;

                continue;
            }

            // Best effort: traz qualidade e limite atuais (o webhook só dá aproximações).
            try {
                $onboarding->refreshPhoneInfo($connection->refresh());
            } catch (WhatsAppApiException $e) {
                $this->line("  · não foi possível atualizar os dados do número ({$e->getCode()})");
            }

            $this->info(sprintf(
                'Conexão #%d (empresa %d): %d criado(s), %d sincronizado(s), status %s.',
                $connection->id,
                $connection->company_id,
                $result['created'],
                $result['synced'],
                $result['status'],
            ));

            foreach ($result['failed'] as $name => $message) {
                $this->warn("  · {$name}: {$message}");
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }
}
