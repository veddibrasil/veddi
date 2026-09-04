<?php

use App\Jobs\MonitorIfoodWebhookHealthJob;
use App\Models\IfoodIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('integração saudável sem receber webhook há tempo anormal é marcada degraded', function () {
    ['integration' => $integration] = ifoodContext('healthA');

    $integration->update([
        'webhook_status' => 'healthy',
        'last_webhook_received_at' => now()->subMinutes(30),
    ]);

    (new MonitorIfoodWebhookHealthJob)->handle();

    $integration->refresh();
    expect($integration->webhook_status)->toBe('degraded');
});

test('integração saudável recebendo webhook recentemente não é afetada', function () {
    ['integration' => $integration] = ifoodContext('healthB');

    $integration->update([
        'webhook_status' => 'healthy',
        'last_webhook_received_at' => now()->subMinutes(2),
    ]);

    (new MonitorIfoodWebhookHealthJob)->handle();

    $integration->refresh();
    expect($integration->webhook_status)->toBe('healthy');
});

test('integração degraded pelo monitor passa a ser coberta pelo PollIfoodEventsJob', function () {
    ['integration' => $integration] = ifoodContext('healthC');

    $integration->update([
        'webhook_status' => 'healthy',
        'last_webhook_received_at' => now()->subMinutes(30),
    ]);

    (new MonitorIfoodWebhookHealthJob)->handle();
    $integration->refresh();

    $covered = IfoodIntegration::withoutGlobalScopes()
        ->where('status', 'active')
        ->whereIn('webhook_status', ['degraded', 'unknown'])
        ->pluck('id');

    expect($covered)->toContain($integration->id);
});
