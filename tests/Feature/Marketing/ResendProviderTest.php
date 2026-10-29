<?php

use App\DTOs\RenderedEmailData;
use App\Exceptions\EmailProviderException;
use App\Services\Marketing\Providers\ResendProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function renderedEmail(): RenderedEmailData
{
    return new RenderedEmailData(
        toEmail: 'dono@empresa.com',
        toName: 'Dono',
        fromEmail: 'novidades@news.veddi.com.br',
        fromName: 'Veddi "Novidades"',
        replyTo: 'contato@veddi.com.br',
        subject: 'Oi',
        html: '<p>Oi</p>',
        text: 'Oi',
        headers: ['List-Unsubscribe' => '<https://app/u/1>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
        tags: ['campaign_id' => '7', 'recipient_id' => '42'],
        idempotencyKey: 'campaign-7-recipient-42',
    );
}

beforeEach(fn () => config(['marketing.resend.key' => 're_test_key', 'marketing.resend.base_url' => 'https://api.resend.com']));

test('envia com Idempotency-Key, tags e cabeçalhos de descadastro', function () {
    Http::fake(['api.resend.com/emails' => Http::response(['id' => 'resend-123'])]);

    expect((new ResendProvider)->send(renderedEmail()))->toBe('resend-123');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Idempotency-Key', 'campaign-7-recipient-42')
            && $request->hasHeader('Authorization', 'Bearer re_test_key')
            && $request['from'] === '"Veddi Novidades" <novidades@news.veddi.com.br>'
            && $request['headers']['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click'
            && $request['tags'] === [['name' => 'campaign_id', 'value' => '7'], ['name' => 'recipient_id', 'value' => '42']];
    });
});

test('422 é falha permanente; 429 e 5xx são temporárias; chave nunca aparece no erro', function () {
    Http::fakeSequence('api.resend.com/*')
        ->push(['name' => 'validation_error', 'message' => 'Invalid `to` field'], 422)
        ->push(['message' => 'Too many requests'], 429)
        ->push(['message' => 'boom'], 503);

    $errors = [];
    foreach (range(1, 3) as $ignored) {
        try {
            (new ResendProvider)->send(renderedEmail());
        } catch (EmailProviderException $exception) {
            $errors[] = $exception;
        }
    }

    expect($errors[0]->permanent)->toBeTrue()
        ->and($errors[1]->permanent)->toBeFalse()
        ->and($errors[2]->permanent)->toBeFalse()
        ->and(collect($errors)->map->getMessage()->implode(' '))->not->toContain('re_test_key');
});

test('sem chave configurada não chama a API', function () {
    config(['marketing.resend.key' => '']);
    Http::fake();

    expect(fn () => (new ResendProvider)->send(renderedEmail()))->toThrow(EmailProviderException::class);
    Http::assertNothingSent();
});
