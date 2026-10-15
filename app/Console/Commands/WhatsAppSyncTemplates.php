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
        {--company= : ID ou slug da empresa. Sem isso, sincroniza as conexões em provisionamento, aguardando templates ou ativas}
        {--platform : Cria/sincroniza os templates na WABA do número da plataforma (homologação e fallback)}
        {--pending : Só as conexões em provisionamento ou aguardando templates (agendado: recupera webhook perdido)}';

    protected $description = 'Reexecuta o provisionamento dos templates de WhatsApp e sincroniza o status com a Meta (suporte).';

    public function handle(WhatsAppTemplateProvisioner $provisioner, WhatsAppOnboardingService $onboarding): int
    {
        if ($this->option('platform')) {
            return $this->syncPlatform($provisioner);
        }

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
        } elseif ($this->option('pending')) {
            $query->whereIn('status', [
                WhatsAppConnection::STATUS_PROVISIONING,
                WhatsAppConnection::STATUS_TEMPLATES_PENDING,
            ]);
        } else {
            $query->whereIn('status', [
                WhatsAppConnection::STATUS_PROVISIONING,
                WhatsAppConnection::STATUS_TEMPLATES_PENDING,
                WhatsAppConnection::STATUS_ACTIVE,
            ]);
        }

        $connections = $query->orderBy('id')->get();

        if ($connections->isEmpty()) {
            if ($this->option('pending')) {
                $this->info('Nenhuma conexão aguardando templates.');

                return self::SUCCESS;
            }

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

    private function syncPlatform(WhatsAppTemplateProvisioner $provisioner): int
    {
        try {
            $result = $provisioner->provisionPlatform();
        } catch (WhatsAppApiException $e) {
            $this->error('Falha na Meta ('.class_basename($e).', código '.$e->getCode().'). Confira o WHATSAPP_PLATFORM_TOKEN (token permanente de usuário do sistema).');

            return self::FAILURE;
        }

        if ($result === null) {
            $this->error('Número da plataforma não configurado (WHATSAPP_PLATFORM_WABA_ID / WHATSAPP_PLATFORM_TOKEN).');

            return self::FAILURE;
        }

        $this->info("Plataforma: {$result['created']} template(s) criado(s).");

        foreach ($result['templates'] as $name => $status) {
            $this->line("  · {$name}: {$status}");
        }

        foreach ($result['failed'] as $name => $message) {
            $this->warn("  · {$name}: {$message}");
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
