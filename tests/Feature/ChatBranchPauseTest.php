<?php

use App\Models\Branch;
use App\Models\BranchPause;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function chatBranchPauseCompany(): Company
{
    return Company::create([
        'name' => 'Empresa Pausa Chat',
        'slug' => 'empresa-pausa-chat-'.uniqid(),
        'order_prefix' => 'PCH',
        'active' => true,
        'status' => 'ACTIVE',
        'email' => 'contato@empresa.com',
    ]);
}

test('chat público fica fechado quando a única filial está em pausa programada', function () {
    $company = chatBranchPauseCompany();

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Principal',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    BranchPause::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'reason' => 'Feriado',
        'recurring_annual' => false,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);

    $response = $this->get('/'.$company->slug);

    $response->assertOk();
    $response->assertSee('fora do horário de atendimento');
    $response->assertDontSee('Explore o cardápio', false);
});

test('chat público funciona normalmente quando a pausa já terminou', function () {
    $company = chatBranchPauseCompany();

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial Principal',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    BranchPause::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'recurring_annual' => false,
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->subDay(),
    ]);

    $response = $this->get('/'.$company->slug);

    $response->assertOk();
    $response->assertDontSee('fora do horário de atendimento');
});
