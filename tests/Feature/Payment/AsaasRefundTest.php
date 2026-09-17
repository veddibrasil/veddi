<?php

use App\Contracts\AsaasServiceInterface;
use App\Models\Payment;
use App\Services\Refund\AsaasRefundGateway;

test('AsaasRefundGateway retorna in_progress quando Asaas aceita o pedido de estorno (REFUND_REQUESTED)', function () {
    $payment = new Payment(['asaas_payment_id' => 'pay_123']);

    $asaas = Mockery::mock(AsaasServiceInterface::class);
    $asaas->shouldReceive('refundPayment')
        ->once()
        ->with('pay_123', 50.0)
        ->andReturn(['id' => 'ref_123', 'status' => 'REFUND_REQUESTED']);

    $result = (new AsaasRefundGateway($asaas))->requestRefund($payment, 50.0);

    expect($result)->toBe([
        'external_refund_id' => 'ref_123',
        'status' => 'in_progress',
        'raw' => ['id' => 'ref_123', 'status' => 'REFUND_REQUESTED'],
    ]);
});

test('AsaasRefundGateway envia o amount informado para suportar estorno parcial', function () {
    $payment = new Payment(['asaas_payment_id' => 'pay_456']);

    $asaas = Mockery::mock(AsaasServiceInterface::class);
    $asaas->shouldReceive('refundPayment')
        ->once()
        ->with('pay_456', 12.34)
        ->andReturn(['id' => 'ref_456', 'status' => 'REFUNDED']);

    (new AsaasRefundGateway($asaas))->requestRefund($payment, 12.34);
});

test('AsaasRefundGateway retorna failed quando Asaas rejeita o estorno', function () {
    $payment = new Payment(['asaas_payment_id' => 'pay_789']);

    $asaas = Mockery::mock(AsaasServiceInterface::class);
    $asaas->shouldReceive('refundPayment')
        ->once()
        ->andReturn(['status' => 'REFUND_DENIED', 'errors' => [['description' => 'Saldo insuficiente']]]);

    $result = (new AsaasRefundGateway($asaas))->requestRefund($payment, 10.0);

    expect($result['status'])->toBe('failed');
    expect($result['external_refund_id'])->toBeNull();
});

test('AsaasRefundGateway falha de forma controlada quando pagamento Asaas não tem ID externo, sem chamar a API', function () {
    $payment = new Payment(['asaas_payment_id' => null]);

    $asaas = Mockery::mock(AsaasServiceInterface::class);
    $asaas->shouldNotReceive('refundPayment');

    $result = (new AsaasRefundGateway($asaas))->requestRefund($payment, 10.0);

    expect($result['status'])->toBe('failed');
    expect($result['external_refund_id'])->toBeNull();
});
