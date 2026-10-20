<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Auditoria 15/10 (superfície) — autenticação (Fortify, prefixo /admin).
 */
function sentMailBodies(): array
{
    return collect(app('mailer')->getSymfonyTransport()->messages())
        ->map(fn ($sent) => $sent->getOriginalMessage()->getHtmlBody().' '.$sent->getOriginalMessage()->getTextBody())
        ->all();
}

test('link de redefinição de senha não usa o host enviado em X-Forwarded-Host (password reset poisoning)', function () {
    User::factory()->create(['email' => 'vitima@example.com']);

    // bootstrap/app.php: trustProxies(at: env('TRUSTED_PROXIES', 'REMOTE_ADDR')) confia em quem
    // conecta direto e inclui HEADER_X_FORWARDED_HOST; ResetPassword monta o link com url(), que
    // usa o host da request. Não há trustHosts() nem URL::forceRootUrl().
    $this->withHeaders(['X-Forwarded-Host' => 'atacante.example', 'X-Forwarded-Proto' => 'https'])
        ->post(route('password.email'), ['email' => 'vitima@example.com']);

    $bodies = sentMailBodies();

    expect($bodies)->not->toBeEmpty()
        ->and(implode("\n", $bodies))->not->toContain('atacante.example');
});

test('pedido de redefinição não revela se o e-mail tem conta (enumeração)', function () {
    User::factory()->create(['email' => 'existe@example.com']);

    $known = $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'existe@example.com']);
    $unknown = $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'nao-existe@example.com']);

    // Esperado: mesma resposta para os dois casos. Observado: o desconhecido volta com erro
    // "Não encontramos um usuário com este endereço de e-mail." na sessão.
    expect(session()->get('errors')?->has('email') ?? false)->toBeFalse();
    $known->assertSessionHasNoErrors();
    $unknown->assertSessionHasNoErrors();
});

test('POST /admin/forgot-password tem limite de tentativas', function () {
    $statuses = collect(range(1, 25))->map(fn ($i) => $this->post(route('password.email'), [
        'email' => "sonda{$i}@example.com",
    ])->status());

    expect($statuses->contains(429))->toBeTrue();
});

test('auto-cadastro do Fortify (/admin/register) está desligado — o cadastro oficial é /cadastro', function () {
    $this->post('/admin/register', [
        'name' => 'Desconhecido',
        'email' => 'qualquer@example.com',
        'password' => 'Senha-forte-123!',
        'password_confirmation' => 'Senha-forte-123!',
    ]);

    expect(User::where('email', 'qualquer@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

test('usuário sem empresa nenhuma não obtém assinatura da chave privada do QZ Tray da plataforma', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    config(['services.qz.private_key' => base64_encode($pem)]);

    // Conta criada pelo auto-cadastro do Fortify: sem empresa, sem papel, e-mail não verificado.
    $loner = User::factory()->create(['email_verified_at' => null]);

    $response = $this->actingAs($loner)->post('/admin/pdv/qz-sign', ['request' => hash('sha512', 'qualquer chamada do QZ')]);

    expect($response->status())->toBeIn([401, 403, 404]);
});

test('controle: garçom da empresa (pdv.waiter_operate) continua obtendo a assinatura do QZ Tray', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    config(['services.qz.private_key' => base64_encode($pem)]);

    ['company' => $company, 'branch' => $branch] = pdvContext();
    $company->update(['status' => 'ACTIVE']);
    $waiter = makeWaiter($company, $branch);

    $toSign = hash('sha512', 'chamada legítima do QZ');
    $response = $this->actingAs($waiter)->post('/admin/pdv/qz-sign', ['request' => $toSign])->assertOk();

    $publicKey = openssl_pkey_get_details($key)['key'];

    expect(openssl_verify($toSign, base64_decode($response->getContent()), $publicKey, OPENSSL_ALGO_SHA512))->toBe(1);
});

test('controle: mensagem de "link enviado" é a mesma para e-mail com e sem conta', function () {
    User::factory()->create(['email' => 'existe2@example.com']);

    $this->from(route('password.request'))->post(route('password.email'), ['email' => 'existe2@example.com']);
    $known = session('status');

    $this->from(route('password.request'))->post(route('password.email'), ['email' => 'ninguem@example.com']);
    $unknown = session('status');

    expect($known)->not->toBeEmpty()->toBe($unknown);
});
