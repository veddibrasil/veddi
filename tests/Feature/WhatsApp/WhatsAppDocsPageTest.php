<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * /docs é público (Route::view sem auth): a seção de WhatsApp é para o restaurante e não pode
 * carregar configuração interna do app Meta nem segredos.
 */
test('a documentação pública tem a seção de WhatsApp para o restaurante', function () {
    $this->get(route('docs'))
        ->assertOk()
        ->assertSee('id="whatsapp"', false)
        ->assertSee('href="#whatsapp"', false)
        ->assertSee('Notificações por WhatsApp')
        ->assertSee('Como conectar')
        ->assertSee('Conectar WhatsApp')
        ->assertSee('cartão cadastrado no WhatsApp Manager')
        ->assertSee('Status da conexão')
        ->assertSee('Qualidade do número')
        ->assertSee('Consentimento do cliente')
        ->assertSee('14 dias sem uso do app')
        ->assertSee('PARAR')
        ->assertSee('Desconectar');
});

test('a seção explica cada status da conexão como aparece na tela', function () {
    $html = $this->get(route('docs'))->getContent();

    foreach (['Não conectado', 'Conectando', 'Conectado', 'Com problema', 'Desconectado', 'Alta (verde)', 'Média (amarela)', 'Baixa (vermelha)'] as $texto) {
        expect($html)->toContain($texto);
    }
});

test('a documentação pública não expõe a configuração interna do app Meta', function () {
    $this->get(route('docs'))
        ->assertOk()
        ->assertDontSee('WHATSAPP_WEBHOOK_VERIFY_TOKEN')
        ->assertDontSee('META_APP_SECRET')
        ->assertDontSee('WHATSAPP_ES_CONFIG_ID')
        ->assertDontSee('/webhooks/whatsapp')
        ->assertDontSee('smb_message_echoes')
        ->assertDontSee('message_template_status_update');
});

test('os textos do restaurante batem com os rótulos reais da tela de WhatsApp', function () {
    $view = file_get_contents(resource_path('views/livewire/admin/settings/whats-app-settings.blade.php'));
    $docs = $this->get(route('docs'))->getContent();

    foreach (['Conectar WhatsApp', 'Reconectar WhatsApp', 'Enviar teste', 'Desconectar'] as $rotulo) {
        expect($view)->toContain($rotulo)
            ->and($docs)->toContain($rotulo);
    }
});

test('a documentação interna existe e cobre a configuração do app Meta', function () {
    $interna = file_get_contents(base_path('docs/whatsapp.md'));

    foreach ([
        'https://app.veddi.com.br/webhooks/whatsapp',
        'WHATSAPP_WEBHOOK_VERIFY_TOKEN',
        'messages',
        'message_template_status_update',
        'phone_number_quality_update',
        'account_update',
        'account_alerts',
        'smb_message_echoes',
        'whatsapp:check-connections',
        'awaiting_payment',
    ] as $trecho) {
        expect($interna)->toContain($trecho);
    }
});

test('o CLAUDE.md documenta a arquitetura do WhatsApp e a pendência awaiting_payment', function () {
    $claude = file_get_contents(base_path('CLAUDE.md'));

    foreach (['Notificacoes por WhatsApp', 'WhatsAppService', 'WhatsAppOnboardingService', 'whatsapp:send-test', 'whatsapp:sync-templates', 'whatsapp:check-connections', 'awaiting_payment', 'docs/whatsapp.md'] as $trecho) {
        expect($claude)->toContain($trecho);
    }
});
