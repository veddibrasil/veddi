<?php

use App\Services\Chat\WhatsAppCodeSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('WhatsApp autentica apenas com template aprovado na categoria correta e usa o código no corpo e botão', function () {
    $company = whatsappOrderContext()['company'];
    config(['chat_security.whatsapp_auth_enabled' => true]);
    whatsappFakeMeta(['GET message_templates' => ['data' => [[
        'name' => config('chat_security.whatsapp_auth_template'), 'language' => 'pt_BR',
        'status' => 'APPROVED', 'category' => 'AUTHENTICATION',
    ]]]]);
    $logs = whatsappCaptureLogs();
    app(WhatsAppCodeSender::class)->send($company->id, '11977770001', '762593');
    $request = whatsappMetaRequests('POST messages')->sole();
    expect($request['to'])->toBe('5511977770001')
        ->and(data_get($request->data(), 'template.components.0.parameters.0.text'))->toBe('762593')
        ->and(data_get($request->data(), 'template.components.1.parameters.0.text'))->toBe('762593')
        ->and(json_encode($logs->getRecords()))->not->toContain('762593', '11977770001');
});

test('template de notificação ou pendente não pode enviar código', function (string $category, string $status) {
    $company = whatsappOrderContext()['company'];
    config(['chat_security.whatsapp_auth_enabled' => true]);
    whatsappFakeMeta(['GET message_templates' => ['data' => [[
        'name' => config('chat_security.whatsapp_auth_template'), 'language' => 'pt_BR',
        'status' => $status, 'category' => $category,
    ]]]]);
    expect(fn () => app(WhatsAppCodeSender::class)->send($company->id, '11977770001', '762593'))->toThrow(RuntimeException::class);
    expect(whatsappMetaRequests('POST messages'))->toHaveCount(0);
})->with([['UTILITY', 'APPROVED'], ['AUTHENTICATION', 'PENDING']]);

test('erro da Meta não registra código mesmo quando a API o inclui na mensagem de erro', function () {
    $company = whatsappOrderContext()['company'];
    config(['chat_security.whatsapp_auth_enabled' => true]);
    whatsappFakeMeta([
        'GET message_templates' => ['data' => [[
            'name' => config('chat_security.whatsapp_auth_template'), 'language' => 'pt_BR',
            'status' => 'APPROVED', 'category' => 'AUTHENTICATION',
        ]]],
        'POST messages' => Http::response(['error' => ['code' => 132000, 'message' => 'Invalid code 762593']], 400),
    ]);
    $logs = whatsappCaptureLogs();
    expect(fn () => app(WhatsAppCodeSender::class)->send($company->id, '11977770001', '762593'))->toThrow(\App\Exceptions\WhatsAppPermanentException::class, 'Envio de autenticação recusado.');
    expect(json_encode($logs->getRecords()))->not->toContain('762593');
});

test('preparar template de autenticação só exibe definição e não acessa Meta', function () {
    Http::preventStrayRequests();
    $this->artisan('chat:whatsapp-auth-template', ['company' => 1])->assertSuccessful();
    Http::assertNothingSent();
});

test('PARAR retira avisos de comprador visitante sem criar perfil e respeita a empresa', function () {
    $ctx = whatsappOrderContext();
    $ctx['order']->update(['buyer_data' => ['name' => 'Visitante', 'phone' => $ctx['customer']->phone, 'phone_verified' => true, 'whatsapp_opt_in' => true]]);
    $payload = whatsappWebhookPayload($ctx['connection']->waba_id, [whatsappIncomingChange($ctx['connection']->phone_number_id, '55'.$ctx['customer']->phone, 'PARAR')]);
    processWhatsAppWebhook($payload);
    expect($ctx['order']->fresh()->buyer_data['whatsapp_opt_in'])->toBeFalse()
        ->and(app(\App\Services\Messaging\WhatsAppService::class)->prepareNotification($ctx['order']->fresh(), 'preparing'))->toBeNull();
});
