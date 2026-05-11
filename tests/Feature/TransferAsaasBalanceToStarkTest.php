<?php

use App\Contracts\AsaasServiceInterface;
use App\Jobs\TransferAsaasBalanceToStark;

it('does not attempt transfers outside production by default', function () {
    config()->set('services.asaas.allow_transfers_in_non_production', false);
    config()->set('services.asaas.sandbox', false);

    $asaas = Mockery::mock(AsaasServiceInterface::class);
    $asaas->shouldNotReceive('getBalance');
    $asaas->shouldNotReceive('createTransfer');

    (new TransferAsaasBalanceToStark)->handle($asaas);
});

it('does not attempt transfers in sandbox mode', function () {
    config()->set('app.env', 'production');
    config()->set('services.asaas.sandbox', true);

    $asaas = Mockery::mock(AsaasServiceInterface::class);
    $asaas->shouldNotReceive('getBalance');
    $asaas->shouldNotReceive('createTransfer');

    (new TransferAsaasBalanceToStark)->handle($asaas);
});

it('transfers available balance minus reserve when allowed', function () {
    config()->set('services.asaas.allow_transfers_in_non_production', true);
    config()->set('services.asaas.sandbox', false);
    config()->set('services.asaas.platform_stark_pix_key', '12345678000199');
    config()->set('services.asaas.platform_stark_pix_key_type', 'CNPJ');

    $asaas = Mockery::mock(AsaasServiceInterface::class);
    $asaas->shouldReceive('getBalance')->once()->andReturn(['balance' => 100.00]);
    $asaas->shouldReceive('createTransfer')->once()->with(Mockery::on(function ($payload) {
        return $payload['value'] === 95.00 // 100 - 5% reserve
            && $payload['operationType'] === 'PIX';
    }))->andReturn(['id' => 'tr_fake123']);

    (new TransferAsaasBalanceToStark)->handle($asaas);
});

it('skips transfer when amount is below minimum', function () {
    config()->set('services.asaas.allow_transfers_in_non_production', true);
    config()->set('services.asaas.sandbox', false);
    config()->set('services.asaas.platform_stark_pix_key', '12345678000199');

    $asaas = Mockery::mock(AsaasServiceInterface::class);
    $asaas->shouldReceive('getBalance')->once()->andReturn(['balance' => 1.00]);
    $asaas->shouldNotReceive('createTransfer'); // 1.00 - 5% = 0.95 < MIN_TRANSFER(1.00)

    (new TransferAsaasBalanceToStark)->handle($asaas);
});

it('respects custom reserve_ratio from config', function () {
    config()->set('services.asaas.allow_transfers_in_non_production', true);
    config()->set('services.asaas.sandbox', false);
    config()->set('services.asaas.platform_stark_pix_key', '12345678000199');
    config()->set('services.asaas.platform_stark_pix_key_type', 'CNPJ');
    config()->set('services.asaas.reserve_ratio', 0.03);

    $asaas = Mockery::mock(AsaasServiceInterface::class);
    $asaas->shouldReceive('getBalance')->once()->andReturn(['balance' => 100.00]);
    $asaas->shouldReceive('createTransfer')->once()->with(Mockery::on(function ($payload) {
        return $payload['value'] === 97.00 // 100 - 3% reserve
            && $payload['operationType'] === 'PIX';
    }))->andReturn(['id' => 'tr_fake123']);

    (new TransferAsaasBalanceToStark)->handle($asaas);
});

it('skips transfer when pix key is not configured', function () {
    config()->set('app.env', 'production');
    config()->set('services.asaas.sandbox', false);
    config()->set('services.asaas.platform_stark_pix_key', '');

    $asaas = Mockery::mock(AsaasServiceInterface::class);
    $asaas->shouldNotReceive('getBalance');
    $asaas->shouldNotReceive('createTransfer');

    (new TransferAsaasBalanceToStark)->handle($asaas);
});
