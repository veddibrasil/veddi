<?php

namespace App\Console\Commands;

use App\Concerns\FindsCompanyByOption;
use App\Contracts\WhatsAppProviderInterface;
use App\DTOs\WhatsAppSender;
use App\Exceptions\WhatsAppApiException;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Console\Command;

class WhatsAppSendTest extends Command
{
    use FindsCompanyByOption;

    protected $signature = 'whatsapp:send-test
        {phone : Telefone de destino (DDD + número)}
        {--company= : ID ou slug da empresa (usa a conexão dela). Sem isso, usa o número da plataforma}';

    protected $description = 'Envia o template pedido_em_preparo com dados fictícios (homologação). Ignora opt-in do cliente.';

    public function handle(WhatsAppService $service, WhatsAppProviderInterface $provider): int
    {
        $phone = $service->normalizePhone((string) $this->argument('phone'));

        if ($phone === null) {
            $this->error('Telefone inválido. Informe DDD + número de celular (11 dígitos).');

            return self::FAILURE;
        }

        $sender = $this->resolveSender($service);

        if ($sender === null) {
            return self::FAILURE;
        }

        $template = config('whatsapp_templates.templates.preparing');

        try {
            $wamid = $provider->sendTemplate(
                $sender,
                $phone,
                $template['name'],
                $template['example'],
                (string) config('whatsapp_templates.language', 'pt_BR'),
            );
        } catch (WhatsAppApiException $e) {
            $this->error('Falha no envio ('.class_basename($e).', código '.$e->getCode().'): '.$e->getMessage());
            $this->line('O template "'.$template['name'].'" precisa estar APROVADO na WABA do remetente.');

            return self::FAILURE;
        }

        $this->info('Mensagem enviada. wamid: '.$wamid);

        return self::SUCCESS;
    }

    private function resolveSender(WhatsAppService $service): ?WhatsAppSender
    {
        $option = $this->option('company');

        if ($option === null || $option === '') {
            $sender = WhatsAppSender::platform();

            if ($sender === null) {
                $this->error('Número da plataforma não configurado (WHATSAPP_PLATFORM_PHONE_NUMBER_ID / WHATSAPP_PLATFORM_TOKEN).');
            }

            return $sender;
        }

        $company = $this->findCompany((string) $option);

        if (! $company) {
            $this->error("Empresa \"{$option}\" não encontrada.");

            return null;
        }

        $sender = $service->resolveSender($company);

        if ($sender === null) {
            $this->error("A empresa \"{$company->name}\" não tem conexão ativa nem fallback para o número da plataforma.");

            return null;
        }

        $this->line('Remetente: '.($sender->isPlatform() ? 'número da plataforma (fallback)' : "conexão #{$sender->connectionId} da empresa {$company->name}"));

        return $sender;
    }
}
