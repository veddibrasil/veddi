<?php

namespace App\Services\Marketing\Providers;

use App\Contracts\EmailMarketingProviderInterface;
use App\DTOs\ProviderEventData;
use App\DTOs\RenderedEmailData;
use App\Enums\EmailEventType;
use App\Exceptions\EmailProviderException;
use App\Exceptions\InvalidEmailWebhookException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Resend (API HTTP). Envio com Idempotency-Key por destinatário (retry nunca duplica) e tags
 * campaign_id/recipient_id; webhook assinado pelo Svix (svix-id, svix-timestamp, svix-signature).
 * A chave nunca vai para log nem para mensagem de erro.
 */
class ResendProvider implements EmailMarketingProviderInterface
{
    private const TIMESTAMP_TOLERANCE = 300;

    public function name(): string
    {
        return 'resend';
    }

    public function send(RenderedEmailData $email): string
    {
        $key = (string) config('marketing.resend.key');

        if ($key === '') {
            throw EmailProviderException::account('RESEND_API_KEY não configurada.');
        }

        $payload = array_filter([
            'from' => $this->address($email->fromEmail, $email->fromName),
            'to' => [$email->toEmail],
            'subject' => $email->subject,
            'html' => $email->html,
            'text' => $email->text,
            'reply_to' => $email->replyTo ? [$email->replyTo] : null,
            'headers' => $email->headers ?: null,
            'tags' => collect($email->tags)
                ->map(fn (string $value, string $name) => ['name' => $name, 'value' => preg_replace('/[^A-Za-z0-9_-]/', '_', $value)])
                ->values()
                ->all() ?: null,
        ], fn ($value) => $value !== null);

        try {
            $response = Http::baseUrl((string) config('marketing.resend.base_url'))
                ->withToken($key)
                ->acceptJson()
                ->timeout(15)
                ->when($email->idempotencyKey, fn ($request) => $request->withHeaders(['Idempotency-Key' => $email->idempotencyKey]))
                ->post('/emails', $payload);
        } catch (ConnectionException $exception) {
            throw EmailProviderException::temporary('Sem conexão com o Resend.', 0, $exception);
        }

        if ($response->successful() && filled($response->json('id'))) {
            return (string) $response->json('id');
        }

        $status = $response->status();
        $message = Str::limit('Resend '.$status.': '.($response->json('message') ?? $response->json('name') ?? 'erro'), 300);

        // 401/403: chave inválida/revogada ou domínio do remetente não verificado — vale para todos.
        if ($status === 401 || $status === 403) {
            throw EmailProviderException::account($message, $status);
        }

        $temporary = $status === 408 || $status === 409 || $status === 429 || $status >= 500;

        throw new EmailProviderException($message, ! $temporary, $status);
    }

    public function supportsWebhooks(): bool
    {
        return filled(config('marketing.resend.webhook_secret'));
    }

    public function parseWebhook(Request $request): array
    {
        $this->verifySignature($request);

        $payload = $request->json()->all();
        $type = $this->mapType((string) ($payload['type'] ?? ''), $payload['data'] ?? []);

        if ($type === null) {
            return [];
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $tags = $this->tags($data['tags'] ?? []);

        return [new ProviderEventData(
            eventId: (string) $request->header('svix-id'),
            type: $type,
            messageId: isset($data['email_id']) ? (string) $data['email_id'] : null,
            recipientId: isset($tags['recipient_id']) && ctype_digit((string) $tags['recipient_id']) ? (int) $tags['recipient_id'] : null,
            occurredAt: $this->timestamp($payload['created_at'] ?? null),
            detail: isset($data['bounce']['message']) ? Str::limit((string) $data['bounce']['message'], 300) : null,
        )];
    }

    private function verifySignature(Request $request): void
    {
        $secret = (string) config('marketing.resend.webhook_secret');
        $id = (string) $request->header('svix-id');
        $timestamp = (string) $request->header('svix-timestamp');
        $signatures = (string) $request->header('svix-signature');

        if ($secret === '' || $id === '' || ! ctype_digit($timestamp) || $signatures === '') {
            throw new InvalidEmailWebhookException('Assinatura ausente.');
        }

        if (abs(time() - (int) $timestamp) > self::TIMESTAMP_TOLERANCE) {
            throw new InvalidEmailWebhookException('Timestamp fora da janela.');
        }

        $key = base64_decode(Str::after($secret, 'whsec_'), true);

        if ($key === false) {
            throw new InvalidEmailWebhookException('Segredo do webhook inválido.');
        }

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.".$request->getContent(), $key, true));

        foreach (explode(' ', $signatures) as $signature) {
            [$version, $value] = array_pad(explode(',', $signature, 2), 2, '');

            if ($version === 'v1' && hash_equals($expected, $value)) {
                return;
            }
        }

        throw new InvalidEmailWebhookException('Assinatura inválida.');
    }

    /** Abertura e clique vêm do rastreio próprio (pixel/redirect); aqui só entrega e problemas. */
    private function mapType(string $type, mixed $data): ?EmailEventType
    {
        return match ($type) {
            'email.delivered' => EmailEventType::Delivered,
            'email.bounced' => strtolower((string) (is_array($data) ? ($data['bounce']['type'] ?? '') : '')) === 'permanent'
                ? EmailEventType::HardBounce
                : EmailEventType::SoftBounce,
            'email.complained' => EmailEventType::Complaint,
            'email.failed' => EmailEventType::Failed,
            default => null,
        };
    }

    /** O Resend manda tags como objeto {nome: valor} ou lista [{name, value}]. */
    private function tags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        if (array_is_list($tags)) {
            return collect($tags)->filter(fn ($tag) => is_array($tag) && isset($tag['name']))
                ->mapWithKeys(fn (array $tag) => [$tag['name'] => $tag['value'] ?? null])
                ->all();
        }

        return $tags;
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        try {
            // O Resend manda UTC; o banco guarda no fuso da aplicação.
            return (is_string($value) ? CarbonImmutable::parse($value) : CarbonImmutable::now())
                ->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return CarbonImmutable::now();
        }
    }

    private function address(string $email, string $name): string
    {
        $name = trim(str_replace(['"', "\r", "\n"], '', $name));

        return $name === '' ? $email : "\"{$name}\" <{$email}>";
    }
}
