<?php

use App\Contracts\EmailMarketingProviderInterface;
use App\Enums\EmailCampaignStatus;
use App\Enums\EmailRecipientStatus;
use App\Enums\EmailSuppressionReason;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailEvent;
use App\Models\EmailSuppression;
use App\Services\Marketing\Providers\ResendProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

const RESEND_TEST_SECRET = 'whsec_dGVzdGUtc2VncmVkby13ZWJob29rLXZlZGRp';

beforeEach(function () {
    marketingSetup();
    config(['marketing.provider' => 'resend', 'marketing.resend.webhook_secret' => RESEND_TEST_SECRET]);
    app()->instance(EmailMarketingProviderInterface::class, new ResendProvider);
});

/** Monta e assina um webhook no formato Svix (o mesmo que o Resend envia). */
function resendWebhook(array $payload, ?string $id = null, ?int $timestamp = null, ?string $secret = null): array
{
    $id ??= 'msg_'.uniqid();
    $timestamp ??= time();
    $body = json_encode($payload);
    $key = base64_decode(str_replace('whsec_', '', $secret ?? RESEND_TEST_SECRET));
    $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

    return [$body, [
        'svix-id' => $id,
        'svix-timestamp' => (string) $timestamp,
        'svix-signature' => "v1,{$signature}",
        'Content-Type' => 'application/json',
    ]];
}

function postResend(array $payload, ?string $id = null, ?int $timestamp = null, ?string $secret = null)
{
    [$body, $headers] = resendWebhook($payload, $id, $timestamp, $secret);
    $server = collect($headers)->mapWithKeys(fn ($value, $key) => ['HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value])->all();

    return test()->call('POST', route('webhook.email'), [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body);
}

function sentRecipient(array $attributes = []): EmailCampaignRecipient
{
    $campaign = EmailCampaign::factory()->sending()->create();

    return EmailCampaignRecipient::factory()->for($campaign, 'campaign')->status(EmailRecipientStatus::Sent)->create($attributes + [
        'provider_message_id' => 'resend-'.uniqid(),
        'sent_at' => now(),
    ]);
}

test('assinatura inválida, ausente, adulterada ou antiga retorna 401', function () {
    $recipient = sentRecipient();
    $payload = ['type' => 'email.delivered', 'created_at' => now()->toIso8601String(), 'data' => ['email_id' => $recipient->provider_message_id]];

    $this->postJson(route('webhook.email'), $payload)->assertStatus(401);
    postResend($payload, secret: 'whsec_'.base64_encode('outro-segredo'))->assertStatus(401);
    postResend($payload, timestamp: time() - 3600)->assertStatus(401);

    [$body, $headers] = resendWebhook($payload);
    $tampered = str_replace('delivered', 'complained', $body);
    $server = collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all();
    $this->call('POST', route('webhook.email'), [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $tampered)->assertStatus(401);

    expect(EmailEvent::count())->toBe(0)->and($recipient->refresh()->delivered_at)->toBeNull();
});

test('entrega válida marca delivered e o mesmo evento duas vezes conta uma vez', function () {
    $recipient = sentRecipient();
    $payload = ['type' => 'email.delivered', 'created_at' => now()->toIso8601String(), 'data' => ['email_id' => $recipient->provider_message_id]];

    postResend($payload, id: 'msg_repetida')->assertOk();
    postResend($payload, id: 'msg_repetida')->assertOk();

    expect(EmailEvent::where('provider_event_id', 'msg_repetida')->count())->toBe(1)
        ->and($recipient->refresh()->status)->toBe(EmailRecipientStatus::Delivered)
        ->and($recipient->campaign->refresh()->delivered_count)->toBe(1);
});

test('hard bounce e reclamação de spam criam supressão; soft bounce não', function () {
    $bounced = sentRecipient(['email' => 'inexistente@a.com']);
    $complained = sentRecipient(['email' => 'reclamou@a.com']);
    $soft = sentRecipient(['email' => 'cheia@a.com']);

    postResend(['type' => 'email.bounced', 'data' => ['email_id' => $bounced->provider_message_id, 'bounce' => ['type' => 'Permanent', 'message' => 'No such user']]])->assertOk();
    postResend(['type' => 'email.complained', 'data' => ['email_id' => $complained->provider_message_id]])->assertOk();
    postResend(['type' => 'email.bounced', 'data' => ['email_id' => $soft->provider_message_id, 'bounce' => ['type' => 'Transient']]])->assertOk();

    expect(EmailSuppression::where('email', 'inexistente@a.com')->value('reason'))->toBe(EmailSuppressionReason::HardBounce)
        ->and(EmailSuppression::where('email', 'reclamou@a.com')->value('reason'))->toBe(EmailSuppressionReason::Complaint)
        ->and(EmailSuppression::where('email', 'cheia@a.com')->exists())->toBeFalse()
        ->and($bounced->refresh()->status)->toBe(EmailRecipientStatus::Bounced)
        ->and($complained->refresh()->status)->toBe(EmailRecipientStatus::Complained);
});

test('evento acha o destinatário pela tag quando o id ainda não foi gravado', function () {
    $recipient = sentRecipient(['provider_message_id' => null]);

    postResend(['type' => 'email.delivered', 'data' => ['email_id' => 'novo-id', 'tags' => ['campaign_id' => (string) $recipient->campaign_id, 'recipient_id' => (string) $recipient->id]]])->assertOk();

    expect($recipient->refresh()->delivered_at)->not->toBeNull();
});

test('tag de destinatário que não bate com a mensagem é ignorada', function () {
    $recipient = sentRecipient(['provider_message_id' => 'outro']);

    postResend(['type' => 'email.complained', 'data' => ['email_id' => 'forjado', 'tags' => ['recipient_id' => (string) $recipient->id]]])->assertOk();

    expect(EmailSuppression::count())->toBe(0);
});

test('spam acima do limite pausa a campanha e avisa no Discord', function () {
    config(['marketing.health.min_sample' => 10]);
    Log::shouldReceive('channel')->andReturnSelf()->byDefault();
    Log::shouldReceive('info', 'warning')->byDefault();
    Log::shouldReceive('channel')->with('discord')->atLeast()->once()->andReturnSelf();

    $campaign = EmailCampaign::factory()->sending()->create();
    $recipients = EmailCampaignRecipient::factory()->count(20)->for($campaign, 'campaign')->status(EmailRecipientStatus::Sent)
        ->sequence(fn ($sequence) => ['provider_message_id' => 'm'.$sequence->index, 'sent_at' => now()])
        ->create();

    postResend(['type' => 'email.complained', 'data' => ['email_id' => 'm0']])->assertOk();

    expect($campaign->refresh()->status)->toBe(EmailCampaignStatus::Paused)
        ->and($campaign->last_error)->toContain('spam');
});

test('provedor "mail" não aceita webhook', function () {
    config(['marketing.provider' => 'mail']);
    app()->forgetInstance(EmailMarketingProviderInterface::class);

    $this->postJson(route('webhook.email'), ['type' => 'email.delivered'])->assertStatus(401);
});

test('horário do evento do Resend (UTC) é gravado no fuso da aplicação', function () {
    $recipient = sentRecipient();

    postResend(['type' => 'email.delivered', 'created_at' => '2026-10-29T15:00:00.000Z', 'data' => ['email_id' => $recipient->provider_message_id]])->assertOk();

    expect($recipient->refresh()->delivered_at->format('Y-m-d H:i'))->toBe('2026-10-29 12:00');
});
