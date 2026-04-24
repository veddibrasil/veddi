<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config(['services.asaas.webhook_token' => 'valid-test-token']);
});

// ─── Autenticação por token ───────────────────────────────────────────────────

test('webhook rejeita requisição sem token no header', function () {
    $this->postJson('/webhooks/asaas', ['event' => 'PAYMENT_CONFIRMED'])
        ->assertStatus(401)
        ->assertJson(['error' => 'Unauthorized']);
});

test('webhook rejeita token inválido', function () {
    $this->postJson('/webhooks/asaas', ['event' => 'PAYMENT_CONFIRMED'], [
        'asaas-access-token' => 'token-errado',
    ])
        ->assertStatus(401)
        ->assertJson(['error' => 'Unauthorized']);
});

test('webhook rejeita quando configuração de token está vazia', function () {
    config(['services.asaas.webhook_token' => '']);

    $this->postJson('/webhooks/asaas', ['event' => 'PAYMENT_CONFIRMED'], [
        'asaas-access-token' => '',
    ])
        ->assertStatus(401)
        ->assertJson(['error' => 'Unauthorized']);
});

test('webhook rejeita token parcialmente correto', function () {
    $this->postJson('/webhooks/asaas', ['event' => 'PAYMENT_CONFIRMED'], [
        'asaas-access-token' => 'valid-test-toke', // falta um caractere
    ])
        ->assertStatus(401)
        ->assertJson(['error' => 'Unauthorized']);
});

// ─── Validação de payload ─────────────────────────────────────────────────────

test('webhook retorna 422 quando campo event está ausente', function () {
    $this->postJson('/webhooks/asaas', ['payment' => ['id' => 'pay_123']], [
        'asaas-access-token' => 'valid-test-token',
    ])
        ->assertStatus(422)
        ->assertJson(['error' => 'Missing event']);
});

test('webhook aceita token válido com payload completo', function () {
    $this->postJson('/webhooks/asaas', [
        'event' => 'PAYMENT_CONFIRMED',
        'payment' => ['id' => 'pay_123', 'value' => 100.00],
    ], [
        'asaas-access-token' => 'valid-test-token',
    ])
        ->assertStatus(200)
        ->assertJson(['status' => 'queued']);
});

test('webhook enfileira o job ao receber payload válido', function () {
    $this->postJson('/webhooks/asaas', [
        'event' => 'PAYMENT_RECEIVED',
        'payment' => ['id' => 'pay_456'],
    ], [
        'asaas-access-token' => 'valid-test-token',
    ])
        ->assertStatus(200);

    Queue::assertPushed(\App\Jobs\ProcessAsaasWebhook::class);
});

test('webhook não enfileira job com token inválido', function () {
    $this->postJson('/webhooks/asaas', [
        'event' => 'PAYMENT_CONFIRMED',
        'payment' => ['id' => 'pay_789'],
    ], [
        'asaas-access-token' => 'token-invalido',
    ])
        ->assertStatus(401);

    Queue::assertNothingPushed();
});
