<?php

use App\Services\Food99\Food99Signer;

it('assina parâmetros de app com o vetor da documentação', function () {
    $sign = (new Food99Signer)->appSign([
        'timestamp' => 1623814795,
        'page_size' => 30,
        'app_id' => 3458764573578035295,
        'page_no' => 1,
    ], '87b69bab1a1548c1516189a5fb75e705');

    expect($sign)->toBe('d471c1f850ab312a703ac9611bdee25a');
});

it('ignora sign e parâmetros vazios na assinatura', function () {
    $signer = new Food99Signer;
    $base = ['app_id' => '3458764573578035295', 'page_no' => 1, 'page_size' => 30, 'timestamp' => 1623814795];

    expect($signer->appSign($base + ['sign' => 'x', 'vazio' => '', 'nulo' => null], '87b69bab1a1548c1516189a5fb75e705'))
        ->toBe('d471c1f850ab312a703ac9611bdee25a');
});

it('reproduz o quirk do SDK PHP: array entra como a string Array', function () {
    $signer = new Food99Signer;
    $secret = 'segredo';

    expect($signer->appSign(['app_id' => 1, 'shop_infos' => [['shop_id' => 9]], 'timestamp' => 2], $secret))
        ->toBe(md5('app_id=1&shop_infos=Array&timestamp=2'.$secret));
});

it('valida a assinatura do callback sobre o corpo bruto', function () {
    config(['food99.app_secret' => 'segredo-app']);
    $signer = new Food99Signer;
    $raw = '{"type":"orderNew","data":{"order_id":5764607523034934815}}';

    expect($signer->validWebhookSign($raw, md5($raw.'segredo-app')))->toBeTrue()
        ->and($signer->validWebhookSign($raw, strtoupper(md5($raw.'segredo-app'))))->toBeTrue()
        ->and($signer->validWebhookSign($raw.' ', md5($raw.'segredo-app')))->toBeFalse()
        ->and($signer->validWebhookSign($raw, null))->toBeFalse();

    config(['food99.app_secret' => '']);
    expect($signer->validWebhookSign($raw, md5($raw)))->toBeFalse();
});
