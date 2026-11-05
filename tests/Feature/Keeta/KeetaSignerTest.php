<?php

use App\Services\Keeta\KeetaSigner;

beforeEach(fn () => keetaConfig());

it('monta a string de assinatura dos exemplos da documentação', function () {
    $signer = app(KeetaSigner::class);

    expect($signer->signatureString('https://api.example.com/v1/users', ['page' => '2', 'limit' => '10', 'sort' => 'name'], ''))
        ->toBe('https://api.example.com/v1/users&limit=10&page=2&sort=name')
        ->and($signer->signatureString('https://api.example.com/v1/orders', [], '{"userId":123,"productId":456,"quantity":2}'))
        ->toBe('https://api.example.com/v1/orders&{"userId":123,"productId":456,"quantity":2}')
        ->and($signer->signatureString('https://api.example.com/v1/products?x=1', ['version' => 'v2', 'format' => 'json'], '{"name":"Product A","price":99.99}'))
        ->toBe('https://api.example.com/v1/products&format=json&version=v2&{"name":"Product A","price":99.99}');
});

it('deixa corpo vazio e {} fora da string e trata nulo na query como vazio', function () {
    $signer = app(KeetaSigner::class);

    expect($signer->signatureString('https://k.test/v1/merchantUpdate/x', [], '{}'))->toBe('https://k.test/v1/merchantUpdate/x')
        ->and($signer->signatureString('https://k.test/a', ['b' => null, 'a' => true], ''))->toBe('https://k.test/a&a=true&b=');
});

it('assina com HMAC-SHA256 em base64 igual ao script PHP da Keeta', function () {
    $string = 'https://api.example.com/v1/products&format=json&version=v2&{"name":"Product A","price":99.99}';
    $expected = base64_encode(hash_hmac('sha256', $string, 'segredo', true));

    expect(app(KeetaSigner::class)->sign('https://api.example.com/v1/products', ['version' => 'v2', 'format' => 'json'], '{"name":"Product A","price":99.99}', 'segredo'))
        ->toBe($expected);
});

it('canonicaliza o corpo em RFC 8785 (ordem, números, acentos, aninhados)', function () {
    $json = app(KeetaSigner::class)->canonicalize([
        'b' => 1,
        'a' => ['d' => 1.0, 'c' => 'São/Paulo "x"'],
        'e' => [],
        'f' => new stdClass,
        'g' => 1.0e-7,
        'h' => 99.99,
        'i' => true,
        'j' => null,
        'k' => 1.0e30,
        'l' => [3, 'dois', ['z' => 1, 'y' => 2]],
    ]);

    expect($json)->toBe('{"a":{"c":"São/Paulo \"x\"","d":1},"b":1,"e":[],"f":{},"g":1e-7,"h":99.99,"i":true,"j":null,"k":1e+30,"l":[3,"dois",{"y":2,"z":1}]}');
});

it('canonicaliza JSON recebido preservando objeto vazio', function () {
    $signer = app(KeetaSigner::class);

    expect($signer->canonicalizeRaw('{ "z": {}, "a": [ ], "m": 10.50 }'))->toBe('{"a":[],"m":10.5,"z":{}}')
        ->and($signer->canonicalizeRaw('não é json'))->toBeNull();
});

it('valida a assinatura do webhook só do corpo ou com a URL, cru ou canônico', function () {
    $signer = app(KeetaSigner::class);
    $url = 'https://veddi.test/webhooks/keeta/orders';
    $raw = '{"orderId":"1","eventType":"CREATED","eventId":"e1"}';
    $canonical = '{"eventId":"e1","eventType":"CREATED","orderId":"1"}';
    $hmac = fn (string $text) => base64_encode(hash_hmac('sha256', $text, KEETA_TEST_CLIENT_SECRET, true));

    expect($signer->validWebhookSign($raw, $hmac($raw), $url))->toBeTrue()
        ->and($signer->validWebhookSign($raw, $hmac($canonical), $url))->toBeTrue()
        ->and($signer->validWebhookSign($raw, $hmac($url.'&'.$canonical), $url))->toBeTrue()
        ->and($signer->validWebhookSign($raw, $hmac($url.'&'.$raw), $url))->toBeTrue()
        ->and($signer->validWebhookSign($raw, $hmac('outro'), $url))->toBeFalse()
        ->and($signer->validWebhookSign($raw, null, $url))->toBeFalse();

    config(['keeta.client_secret' => '']);
    expect($signer->validWebhookSign($raw, $hmac($raw), $url))->toBeFalse();
});
