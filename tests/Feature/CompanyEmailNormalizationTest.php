<?php

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function companyWithEmail(?string $email): Company
{
    return Company::create([
        'name' => 'Empresa E-mail',
        'slug' => 'empresa-email-'.uniqid(),
        'order_prefix' => 'EML',
        'active' => true,
        'plan' => 'free',
        'status' => 'ACTIVE',
        'email' => $email,
    ]);
}

test('e-mail da empresa é gravado em minúsculas e sem espaços', function () {
    $company = companyWithEmail('  MisterCoxinha.MARINGA@Gmail.COM ');

    expect($company->email)->toBe('mistercoxinha.maringa@gmail.com')
        ->and($company->fresh()->email)->toBe('mistercoxinha.maringa@gmail.com');
});

test('e-mail da empresa continua em minúsculas ao atualizar', function () {
    $company = companyWithEmail('loja@gmail.com');

    $company->update(['email' => 'NOVO.EMAIL@GMAIL.COM']);

    expect($company->fresh()->email)->toBe('novo.email@gmail.com');
});

test('e-mail vazio da empresa vira nulo', function (?string $email) {
    expect(companyWithEmail($email)->fresh()->email)->toBeNull();
})->with([
    'nulo' => [null],
    'vazio' => [''],
    'só espaços' => ['   '],
]);
