<?php

use App\Contracts\ChatCodeSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Drawer\Utils;

require_once __DIR__.'/../Audit/Flows/flows_helpers.php';

uses(RefreshDatabase::class);

/*
 * A página guarda o token CSRF do carregamento: trocar o token numa ação Livewire faz o clique
 * seguinte voltar 419. O teste liga a verificação de CSRF (desligada em ambiente de testes) e usa
 * sessão em banco, relida a cada request como em produção.
 */
beforeEach(function () {
    config([
        'chat_security.code_channel' => 'email',
        'session.driver' => 'database',
        'session.encrypt' => true,
    ]);
    $this->app['env'] = 'local';

    $this->codeSender = new class implements ChatCodeSender
    {
        public string $code = '';

        public function send(int $companyId, string $phone, string $code, ?string $challengeId = null): void
        {
            $this->code = $code;
        }
    };
    $this->app->instance(ChatCodeSender::class, $this->codeSender);
});

function csrfChat(Tests\TestCase $test, string $slug): object
{
    return new class($test, $slug)
    {
        public string $snapshot;

        public array $data;

        private string $csrf;

        public function __construct(private Tests\TestCase $test, string $slug)
        {
            $this->freshSession();
            $response = $this->test->get('/'.$slug)->assertOk();
            preg_match('/<meta name="csrf-token" content="([^"]+)"/', $response->getContent(), $match);
            $this->csrf = $match[1];
            $this->snapshot = json_encode(Utils::extractAttributeDataFromHtml($response->getContent(), 'wire:snapshot'));
            $this->data = json_decode($this->snapshot, true)['data'];
            $this->keepCookies($response);
        }

        public function call(string $method, array $params = [], array $updates = []): int
        {
            $this->freshSession();
            $response = $this->test->withCredentials()->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
                '_token' => $this->csrf,
                'components' => [['snapshot' => $this->snapshot, 'calls' => [['path' => '', 'method' => $method, 'params' => $params]], 'updates' => $updates]],
            ]);

            if ($response->getStatusCode() === 200) {
                $this->snapshot = $response->json('components.0.snapshot');
                $this->data = json_decode($this->snapshot, true)['data'];
                $this->keepCookies($response);
            }

            return $response->getStatusCode();
        }

        private function freshSession(): void
        {
            app('session')->forgetDrivers();
            app()->forgetInstance('session.store');
        }

        private function keepCookies($response): void
        {
            foreach ($response->headers->getCookies() as $cookie) {
                $this->test->withUnencryptedCookie($cookie->getName(), (string) $cookie->getValue());
            }
        }
    };
}

function verifyPhoneInChat(Tests\TestCase $test, object $chat, array $ctx, string $phone): void
{
    $chat->call('confirmCart', updates: ['cart' => [$ctx['product']->id => ['qty' => 1, 'price' => 20, 'name' => 'Coxinha']]]);
    $chat->call('selectOrderType', ['pickup']);
    $chat->call('submitPhone', updates: ['phone' => $phone]);
    $chat->call('useSavedData');
    expect($chat->data['step'])->toBe('VERIFY_PHONE');

    expect($chat->call('submitVerificationCode', updates: ['verificationCode' => $test->codeSender->code]))->toBe(200);
}

test('telefone sem cadastro confirma o código e passa da etapa do nome', function () {
    $ctx = flowAuditContext();
    $chat = csrfChat($this, $ctx['company']->slug);

    verifyPhoneInChat($this, $chat, $ctx, '11988887777');
    expect($chat->data['step'])->toBe('REGISTER_NAME');

    expect($chat->call('submitName', updates: ['name' => 'Cliente Novo']))->toBe(200)
        ->and($chat->data['step'])->toBe('CHECKOUT_COUPON');
});

test('cliente com cadastro confirma o código e segue para o cupom', function () {
    $ctx = flowAuditContext();
    $chat = csrfChat($this, $ctx['company']->slug);

    verifyPhoneInChat($this, $chat, $ctx, $ctx['customer']->phone);
    expect($chat->data['step'])->toBe('CHECKOUT_COUPON');

    expect($chat->call('skipCoupon'))->toBe(200);
});

test('o clique seguinte a esquecer o aparelho não expira', function () {
    $ctx = flowAuditContext();
    $chat = csrfChat($this, $ctx['company']->slug);

    expect($chat->call('forgetDevice'))->toBe(200)
        ->and($chat->call('confirmCart', updates: ['cart' => [$ctx['product']->id => ['qty' => 1, 'price' => 20, 'name' => 'Coxinha']]]))->toBe(200);
});

test('confirmar o telefone troca o id da sessão', function () {
    $ctx = flowAuditContext();
    $chat = csrfChat($this, $ctx['company']->slug);
    $before = DB::table('sessions')->pluck('id');

    verifyPhoneInChat($this, $chat, $ctx, $ctx['customer']->phone);

    expect(DB::table('sessions')->pluck('id')->diff($before))->not->toBeEmpty();
});
