<?php

use App\Contracts\ChatCodeSender;
use App\Livewire\Chat\OrderChat;
use App\Mail\ChatVerificationCode;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\EmailCodeSender;
use App\Services\Chat\UnavailableCodeSender;
use App\Services\Chat\WhatsAppCodeSender;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Audit/Flows/flows_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['chat_security.code_channel' => 'email']);
    Mail::fake();
});

function emailAuthenticationChat(array $ctx): OrderChat
{
    $chat = new OrderChat;
    $chat->companyId = $ctx['company']->id;
    $chat->phone = $ctx['customer']->phone;

    return $chat;
}

test('canal email enfileira código para o destinatário correto sem código no assunto', function () {
    $ctx = flowAuditContext();
    app(ChatCodeSender::class)->send($ctx['company']->id, $ctx['customer']->phone, '076259');
    Mail::assertQueued(ChatVerificationCode::class, function ($mail) use ($ctx) {
        expect($mail)->toBeInstanceOf(ShouldQueue::class)->toBeInstanceOf(ShouldBeEncrypted::class)
            ->and($mail->code)->toBe('076259')
            ->and($mail->envelope()->subject)->toBe('Seu código de acesso — '.$ctx['company']->name)
            ->and($mail->envelope()->subject)->not->toContain('076259')
            ->and($mail->render())->toContain('076259', $ctx['company']->name, '5 minutos', 'Se não foi você, ignore este e-mail');

        return $mail->hasTo($ctx['customer']->email);
    });
    Mail::assertQueuedCount(1);
});

test('resposta é igual com cadastro ausente email vazio inválido ou válido', function () {
    $ctx = flowAuditContext();
    $responses = [];
    foreach (['valid', 'empty', 'invalid', 'missing'] as $scenario) {
        Mail::fake();
        session()->flush();
        $this->travel(11)->minutes();
        if ($scenario === 'empty') {
            $ctx['customer']->update(['email' => null]);
        } elseif ($scenario === 'invalid') {
            $ctx['customer']->update(['email' => 'invalid-email']);
        } elseif ($scenario === 'missing') {
            $ctx['customer']->delete();
        }
        $chat = emailAuthenticationChat($ctx);
        $chat->requestVerificationCode();
        $responses[] = [$chat->step, $chat->messages, $chat->getErrorBag()->toArray()];
        expect($chat->challengeId)->not->toBeNull();
        if ($scenario === 'valid') {
            Mail::assertQueued(ChatVerificationCode::class);
        } else {
            Mail::assertNothingQueued();
        }
    }
    expect($responses[0])->toBe(['VERIFY_PHONE', [], []]);
    foreach ($responses as $response) {
        expect($response)->toBe($responses[0]);
    }
});

test('telefone igual em outra empresa não recebe o código', function () {
    $first = flowAuditContext();
    $second = flowAuditContext();
    $first['customer']->update(['email' => null]);
    app(EmailCodeSender::class)->send($first['company']->id, $second['customer']->phone, '762593');
    Mail::assertNothingQueued();
    $first['customer']->update(['email' => 'primeira@example.com']);
    app(EmailCodeSender::class)->send($first['company']->id, $second['customer']->phone, '762593');
    Mail::assertQueued(ChatVerificationCode::class, fn ($mail) => $mail->hasTo('primeira@example.com') && ! $mail->hasTo($second['customer']->email));
    Mail::assertQueuedCount(1);
});

test('código por email confirma perfil e não libera perfil de outra empresa', function () {
    $ctx = flowAuditContext();
    $chat = emailAuthenticationChat($ctx);
    $chat->requestVerificationCode();
    expect($chat->customerId)->toBeNull();
    $mail = Mail::queued(ChatVerificationCode::class)->sole();
    $chat->verificationCode = $mail->code;
    $chat->submitVerificationCode();
    expect($chat->customerId)->toBe($ctx['customer']->id)
        ->and($chat->name)->toBe($ctx['customer']->name)
        ->and($chat->useSavedProfile)->toBeTrue()
        ->and($chat->checkoutReady)->toBeTrue()
        ->and(app(ChatAccess::class)->customer($ctx['company']->id + 1))->toBeNull();
});

test('cinco códigos errados bloqueiam o desafio mesmo com código correto depois', function () {
    $ctx = flowAuditContext();
    $chat = emailAuthenticationChat($ctx);
    $chat->requestVerificationCode();
    $code = Mail::queued(ChatVerificationCode::class)->sole()->code;
    $wrong = $code === '000000' ? '000001' : '000000';
    $challenge = $chat->challengeId;
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $chat->verificationCode = $wrong;
        $chat->submitVerificationCode();
        expect(DB::table('chat_phone_challenges')->where('id', $challenge)->value('attempts'))->toBe($attempt)
            ->and($chat->customerId)->toBeNull();
    }
    $chat->verificationCode = $code;
    $chat->submitVerificationCode();
    expect($chat->customerId)->toBeNull()
        ->and($chat->getErrorBag()->first('verificationCode'))->toContain('Código inválido ou expirado');
});

test('código por email vence em cinco minutos', function () {
    $ctx = flowAuditContext();
    $chat = emailAuthenticationChat($ctx);
    $chat->requestVerificationCode();
    $chat->verificationCode = Mail::queued(ChatVerificationCode::class)->sole()->code;
    $this->travel(5)->minutes();
    $chat->submitVerificationCode();
    expect($chat->customerId)->toBeNull()
        ->and($chat->getErrorBag()->first('verificationCode'))->toContain('Código inválido ou expirado');
});

test('envio e confirmação não registram telefone email ou código em logs', function () {
    $ctx = flowAuditContext();
    Log::spy();
    $chat = emailAuthenticationChat($ctx);
    $chat->requestVerificationCode();
    $chat->verificationCode = Mail::queued(ChatVerificationCode::class)->sole()->code;
    $chat->submitVerificationCode();
    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log', 'channel'] as $method) {
        Log::shouldNotHaveReceived($method);
    }
});

test('binding escolhe canal configurado e exige habilitação para WhatsApp', function (string $channel, bool $enabled, string $expected) {
    config(['chat_security.code_channel' => $channel, 'chat_security.whatsapp_auth_enabled' => $enabled]);
    expect(app(ChatCodeSender::class))->toBeInstanceOf($expected);
})->with([
    ['email', false, EmailCodeSender::class],
    ['email', true, EmailCodeSender::class],
    ['whatsapp', true, WhatsAppCodeSender::class],
    ['whatsapp', false, UnavailableCodeSender::class],
    ['none', true, UnavailableCodeSender::class],
    ['unknown', true, UnavailableCodeSender::class],
]);

test('payload real da fila mantém código e destinatário criptografados', function () {
    $mail = (new ChatVerificationCode('076259', 'Loja Teste'))->to('privado@example.com');
    $job = new SendQueuedMailable($mail);
    expect($job->shouldBeEncrypted)->toBeTrue();
    Queue::connection('database')->push($job);
    $payload = json_decode(DB::table('jobs')->sole()->payload, true);
    $command = $payload['data']['command'];
    expect($command)->not->toContain('076259', 'privado@example.com');
    $restored = unserialize(Crypt::decrypt($command));
    expect($restored->mailable->code)->toBe('076259')
        ->and($restored->mailable->hasTo('privado@example.com'))->toBeTrue();
});

test('texto do chat não revela endereço e respeita o canal', function (string $channel) {
    $ctx = flowAuditContext();
    config(['chat_security.code_channel' => $channel]);
    $initialHtml = $this->get('/'.$ctx['company']->slug)->assertOk()->getContent();
    $snapshot = json_encode(\Livewire\Drawer\Utils::extractAttributeDataFromHtml($initialHtml, 'wire:snapshot'));
    $response = $this->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'calls' => [], 'updates' => [
            'step' => 'VERIFY_PHONE', 'phone' => $ctx['customer']->phone,
        ]]],
    ])->assertOk();
    $html = $response->json('components.0.effects.html');
    expect($html)->not->toContain($ctx['customer']->email);
    if ($channel === 'email') {
        expect($html)->toContain('Se houver um e-mail cadastrado para este telefone, enviamos um código.', 'Ele vale por 5 minutos.');
    } else {
        expect($html)->toContain('Código enviado pelo WhatsApp para '.$ctx['customer']->phone);
    }
})->with(['email', 'whatsapp']);
