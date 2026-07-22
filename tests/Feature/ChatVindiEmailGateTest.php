<?php

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function chatVindiEmailGateCompany(array $attributes = []): Company
{
    return Company::create(array_merge([
        'name' => 'Empresa Gate Vindi',
        'slug' => 'empresa-gate-vindi-'.uniqid(),
        'order_prefix' => 'GVD',
        'active' => true,
        'status' => 'ACTIVE',
    ], $attributes))->fresh();
}

test('chat público bloqueia pedidos quando empresa não tem email cadastrado na Vindi', function () {
    $company = chatVindiEmailGateCompany(['email' => null]);

    Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Principal',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $response = $this->get('/'.$company->slug);

    $response->assertOk();
    $response->assertSee('Atendimento indisponível');
    $response->assertDontSee('Explore o cardápio', false);
});

test('chat público funciona normalmente quando empresa tem email cadastrado na Vindi', function () {
    $company = chatVindiEmailGateCompany(['email' => 'contato@empresa.com']);

    Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Principal',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $response = $this->get('/'.$company->slug);

    $response->assertOk();
    $response->assertDontSee('Atendimento indisponível');
});
