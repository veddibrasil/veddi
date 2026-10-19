<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// O auto-cadastro do Fortify (/admin/register) foi desligado: criava conta sem empresa.
// O cadastro oficial é /cadastro (Onboarding\RegisterForm), coberto em tests/Feature/Onboarding.

test('auto-cadastro do Fortify não está registrado', function () {
    expect(Route::has('register'))->toBeFalse();

    $this->get('/admin/register')->assertNotFound();
});

test('POST /admin/register não cria usuário', function () {
    $this->post('/admin/register', [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

test('tela de cadastro oficial (/cadastro) pode ser renderizada', function () {
    $this->get(route('register.create'))->assertOk();
});
