<?php

use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;

test('erros conhecidos da Meta viram texto em português para o restaurante', function (string $code, string $trecho) {
    $message = new WhatsAppMessage(['status' => WhatsAppMessage::STATUS_FAILED, 'error_code' => $code, 'error_message' => 'Technical english text']);

    expect($message->friendlyError())->toContain($trecho)
        ->and($message->friendlyError())->not->toContain('Technical english text');
})->with([
    'número sem WhatsApp' => ['131026', 'pode não ter WhatsApp'],
    'cobrança' => ['131042', 'forma de pagamento'],
    'cliente pediu para parar' => ['131050', 'pediu para não receber'],
    'template pausado' => ['132015', 'pausado'],
    'token' => ['190', 'Reconecte o número'],
]);

test('código desconhecido mostra só o código, sem repassar o texto técnico da Meta', function () {
    $message = new WhatsAppMessage(['status' => WhatsAppMessage::STATUS_FAILED, 'error_code' => '999999', 'error_message' => 'Some internal Meta detail']);

    expect($message->friendlyError())->toBe('Falha no envio informada pela Meta (código 999999).');
});

test('sem código o texto é o nosso (já em português) ou um genérico', function () {
    $own = new WhatsAppMessage(['status' => WhatsAppMessage::STATUS_FAILED, 'error_message' => 'Envio cancelado: condições não atendidas no momento do envio.']);
    $empty = new WhatsAppMessage(['status' => WhatsAppMessage::STATUS_FAILED]);

    expect($own->friendlyError())->toBe('Envio cancelado: condições não atendidas no momento do envio.')
        ->and($empty->friendlyError())->toBe('Não foi possível enviar a mensagem.');
});

test('mensagem que não falhou não tem erro para mostrar', function (string $status) {
    $message = new WhatsAppMessage(['status' => $status, 'error_code' => '131026']);

    expect($message->friendlyError())->toBeNull();
})->with([WhatsAppMessage::STATUS_QUEUED, WhatsAppMessage::STATUS_SENT, WhatsAppMessage::STATUS_DELIVERED, WhatsAppMessage::STATUS_READ]);

test('rótulos de evento e status são amigáveis, inclusive os dois tipos de "pronto"', function () {
    expect((new WhatsAppMessage(['event' => 'new_order']))->eventLabel())->toBe('Pedido recebido')
        ->and((new WhatsAppMessage(['event' => 'ready']))->eventLabel())->toBe('Pronto')
        ->and(WhatsAppTemplate::eventLabel('ready_pickup'))->toBe('Pronto para retirada')
        ->and(WhatsAppTemplate::eventLabel('ready_delivery'))->toBe('Pronto para entrega')
        ->and(WhatsAppTemplate::eventLabel('evento_novo'))->toBe('evento_novo')
        ->and((new WhatsAppMessage(['status' => 'read']))->statusLabel())->toBe('Lida')
        ->and((new WhatsAppMessage(['status' => 'failed']))->statusLabel())->toBe('Falhou');
});
