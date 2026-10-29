<?php

namespace Tests\Fakes;

use App\Contracts\EmailMarketingProviderInterface;
use App\DTOs\ProviderEventData;
use App\DTOs\RenderedEmailData;
use App\Exceptions\EmailProviderException;
use App\Exceptions\InvalidEmailWebhookException;
use Illuminate\Http\Request;

/** Provedor de e-mail marketing em memória para os testes. */
class FakeEmailMarketingProvider implements EmailMarketingProviderInterface
{
    /** @var list<RenderedEmailData> */
    public array $sent = [];

    /** @var array<string, EmailProviderException> e-mail => exceção a lançar */
    public array $failures = [];

    /** @var list<ProviderEventData> eventos devolvidos pelo webhook com o token certo */
    public array $webhookEvents = [];

    public string $webhookToken = 'fake-token';

    public function name(): string
    {
        return 'fake';
    }

    public function send(RenderedEmailData $email): string
    {
        if (isset($this->failures[$email->toEmail])) {
            throw $this->failures[$email->toEmail];
        }

        $this->sent[] = $email;

        return 'msg-'.count($this->sent).'-'.md5($email->toEmail.$email->subject);
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function parseWebhook(Request $request): array
    {
        if ($request->header('X-Fake-Token') !== $this->webhookToken) {
            throw new InvalidEmailWebhookException('Assinatura inválida.');
        }

        return $this->webhookEvents;
    }

    /** @return list<string> */
    public function sentTo(): array
    {
        return array_map(fn (RenderedEmailData $email) => $email->toEmail, $this->sent);
    }
}
