<?php

use App\Enums\EmailSuppressionReason;
use App\Livewire\Onboarding\RegisterForm;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function registerFreeCompany(bool $optIn): User
{
    $email = 'novo-'.uniqid().'@example.com';

    Livewire::test(RegisterForm::class)
        ->set('companyName', 'Empresa Nova')
        ->set('slug', 'empresa-nova-'.uniqid())
        ->set('branchName', 'Matriz')
        ->set('branchPhone', '(11) 91234-5678')
        ->set('userName', 'Fulano')
        ->set('userEmail', $email)
        ->set('userPassword', 'SenhaForte1!')
        ->set('marketingOptIn', $optIn)
        ->set('plan', 'free')
        ->set('asaasCpfCnpj', '123.456.789-09')
        ->call('submit');

    return User::where('email', $email)->firstOrFail();
}

beforeEach(function () {
    $this->mock(\App\Contracts\AsaasServiceInterface::class, fn ($mock) => $mock->shouldReceive('createCustomer')->andReturn('cus_test'));
});

test('cadastro com aceite grava a data do opt-in', function () {
    expect(registerFreeCompany(true)->marketing_opt_in_at)->not->toBeNull();
});

test('cadastro sem aceite não grava opt-in (checkbox vem desmarcado)', function () {
    Livewire::test(RegisterForm::class)->assertSet('marketingOptIn', false);

    expect(registerFreeCompany(false)->marketing_opt_in_at)->toBeNull();
});

test('perfil liga e desliga o recebimento e tira a supressão de descadastro', function () {
    $user = User::factory()->create(['email' => 'perfil@a.com']);
    EmailSuppression::create(['email' => 'perfil@a.com', 'reason' => EmailSuppressionReason::Unsubscribed]);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->assertSet('marketingOptIn', false)
        ->set('marketingOptIn', true);

    expect($user->refresh()->wantsMarketingEmails())->toBeTrue()
        ->and(EmailSuppression::count())->toBe(0);

    Livewire::test('pages::settings.profile')->set('marketingOptIn', false);

    expect($user->refresh()->wantsMarketingEmails())->toBeFalse()
        ->and($user->marketing_opt_out_at)->not->toBeNull();
});
