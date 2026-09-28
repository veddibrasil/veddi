<?php

use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.meta.app_secret' => 'app-secret-teste',
        'services.whatsapp.webhook_verify_token' => 'verify-token-teste',
    ]);
});

// ── GET: verificação do endpoint ──────────────────────────────────────────────

test('verificação devolve o challenge puro em text/plain', function () {
    $response = $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-token-teste&hub.challenge=1158201444');

    $response->assertOk();

    expect($response->getContent())->toBe('1158201444')
        ->and($response->headers->get('Content-Type'))->toStartWith('text/plain');
});

test('verificação aceita a query já convertida pelo PHP (hub_mode)', function () {
    $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-token-teste&hub_challenge=abc123')
        ->assertOk()
        ->assertSeeText('abc123', false);
});

test('verificação com token errado devolve 403', function () {
    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=errado&hub.challenge=123')
        ->assertForbidden();
});

test('verificação com modo diferente de subscribe devolve 403', function () {
    $this->get('/webhooks/whatsapp?hub.mode=unsubscribe&hub.verify_token=verify-token-teste&hub.challenge=123')
        ->assertForbidden();
});

test('verificação sem challenge devolve 403', function () {
    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-token-teste')
        ->assertForbidden();
});

test('verificação sem token configurado recusa tudo, inclusive token vazio', function () {
    config(['services.whatsapp.webhook_verify_token' => null]);

    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=&hub.challenge=123')
        ->assertForbidden();

    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=123')
        ->assertForbidden();
});

// ── POST: assinatura ──────────────────────────────────────────────────────────

test('POST com assinatura válida responde 200 e enfileira o processamento', function () {
    Queue::fake();

    $payload = whatsappWebhookPayload('111', [whatsappStatusChange('999', 'wamid.X', 'delivered')]);

    whatsappSignedPost($this, $payload)
        ->assertOk()
        ->assertJson(['status' => 'queued']);

    Queue::assertPushed(ProcessWhatsAppWebhook::class, 1);
    Queue::assertPushedOn('whatsapp', ProcessWhatsAppWebhook::class, fn (ProcessWhatsAppWebhook $job) => $job->payload === $payload);
});

test('POST com assinatura inválida devolve 401 e não enfileira', function () {
    Queue::fake();

    whatsappSignedPost($this, whatsappWebhookPayload('111', []), 'sha256='.str_repeat('0', 64))
        ->assertStatus(401);

    Queue::assertNothingPushed();
});

test('POST sem cabeçalho de assinatura devolve 401', function () {
    Queue::fake();

    whatsappSignedPost($this, whatsappWebhookPayload('111', []), '')
        ->assertStatus(401);

    Queue::assertNothingPushed();
});

test('POST assinado com outro segredo devolve 401', function () {
    Queue::fake();

    whatsappSignedPost($this, whatsappWebhookPayload('111', []), null, 'segredo-de-outro-app')
        ->assertStatus(401);

    Queue::assertNothingPushed();
});

test('POST com corpo adulterado depois de assinado devolve 401', function () {
    Queue::fake();

    $original = whatsappWebhookPayload('111', [whatsappStatusChange('999', 'wamid.X', 'delivered')]);
    $signature = 'sha256='.hash_hmac('sha256', json_encode($original), 'app-secret-teste');

    $adulterado = $original;
    $adulterado['entry'][0]['id'] = '222';

    whatsappSignedPost($this, $adulterado, $signature)->assertStatus(401);

    Queue::assertNothingPushed();
});

test('assinatura sem o prefixo sha256= é recusada', function () {
    Queue::fake();

    $payload = whatsappWebhookPayload('111', []);

    whatsappSignedPost($this, $payload, hash_hmac('sha256', json_encode($payload), 'app-secret-teste'))
        ->assertStatus(401);

    Queue::assertNothingPushed();
});

test('sem app secret configurado recusa tudo (fail-closed)', function () {
    Queue::fake();
    config(['services.meta.app_secret' => null]);

    // Um atacante que assine com segredo vazio não pode passar.
    $payload = whatsappWebhookPayload('111', []);

    whatsappSignedPost($this, $payload, 'sha256='.hash_hmac('sha256', json_encode($payload), ''))
        ->assertStatus(401);

    Queue::assertNothingPushed();
});

test('assinatura inválida registra warning sem vazar segredo, assinatura ou corpo', function () {
    $handler = whatsappCaptureLogs();

    $payload = whatsappWebhookPayload('111', [whatsappIncomingChange('999', '5511999990001', 'Quero 2 coxinhas')]);

    whatsappSignedPost($this, $payload, 'sha256=assinatura-forjada-123')->assertStatus(401);

    $logged = json_encode($handler->getRecords());

    expect($handler->hasWarningThatContains('assinatura inválida'))->toBeTrue()
        ->and($logged)->not->toContain('app-secret-teste')
        ->and($logged)->not->toContain('assinatura-forjada-123')
        ->and($logged)->not->toContain('Quero 2 coxinhas')
        ->and($logged)->not->toContain('5511999990001');
});

test('POST válido registra só metadados, nunca telefone nem texto do cliente', function () {
    Queue::fake();
    $handler = whatsappCaptureLogs();

    whatsappSignedPost($this, whatsappWebhookPayload('111', [whatsappIncomingChange('999', '5511999990001', 'Quero 2 coxinhas')]))
        ->assertOk();

    $logged = json_encode($handler->getRecords());

    expect($handler->hasInfoThatContains('Webhook WhatsApp recebido'))->toBeTrue()
        ->and($logged)->not->toContain('Quero 2 coxinhas')
        ->and($logged)->not->toContain('5511999990001');
});

test('objeto que não é da WhatsApp Business responde 200 sem enfileirar', function () {
    Queue::fake();

    whatsappSignedPost($this, ['object' => 'page', 'entry' => []])->assertOk();

    Queue::assertNothingPushed();
});

test('POST não exige token CSRF nem sessão', function () {
    Queue::fake();

    whatsappSignedPost($this, whatsappWebhookPayload('111', []))->assertOk();
});

// ── Fim a fim ─────────────────────────────────────────────────────────────────

test('fim a fim: webhook assinado atualiza o status da mensagem da conexão certa', function () {
    $connection = WhatsAppConnection::factory()->create(['waba_id' => '111', 'phone_number_id' => '999']);
    $message = whatsappSentMessage($connection, 'wamid.FIMAFIM');

    whatsappSignedPost($this, whatsappWebhookPayload('111', [
        whatsappStatusChange('999', 'wamid.FIMAFIM', 'delivered'),
    ]))->assertOk();

    expect($message->fresh()->status)->toBe('delivered');
});
