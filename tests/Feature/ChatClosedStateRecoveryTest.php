<?php

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function chatClosedStateRecoveryCompany(): Company
{
    return Company::create([
        'name' => 'Empresa Recovery',
        'slug' => 'empresa-recovery-'.uniqid(),
        'order_prefix' => 'REC',
        'active' => true,
        'status' => 'ACTIVE',
        'email' => 'contato@empresa.com',
    ]);
}

test('chat sai do estado CLOSED salvo na sessão quando a filial já está aberta de novo', function () {
    $company = chatClosedStateRecoveryCompany();

    Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Principal',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $response = $this->withSession([
        'chat_state' => [
            'step' => 'CLOSED',
            'companyId' => $company->id,
            'messages' => [],
        ],
    ])->get('/'.$company->slug);

    $response->assertOk();
    $response->assertDontSee('fora do horário de atendimento');
});

test('chat mantém CLOSED na sessão quando a filial continua fechada', function () {
    $company = chatClosedStateRecoveryCompany();

    Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Principal',
        'active' => false,
    ]);

    $response = $this->withSession([
        'chat_state' => [
            'step' => 'CLOSED',
            'companyId' => $company->id,
            'messages' => [],
        ],
    ])->get('/'.$company->slug);

    $response->assertOk();
    $response->assertSee('fora do horário de atendimento');
});
