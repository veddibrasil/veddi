<?php

use App\Livewire\Admin\Pdv\Terminal;
use App\Models\Branch;
use App\Models\PdvCashSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Cria $total fechamentos na filial; CX-{prefix}00 é o mais recente. */
function closedSessionsFor(object $company, object $branch, object $user, string $prefix, int $total): void
{
    for ($i = 0; $i < $total; $i++) {
        $s = PdvCashSession::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'terminal_name' => 'CX-'.$prefix.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'opening_amount' => 10,
            'closing_amount' => 10,
            'expected_amount' => 10,
            'closed_at' => now()->subMinutes($i),
        ]);
        // created_at não é fillable: grava depois do create para controlar a ordem.
        $s->forceFill(['created_at' => now()->subMinutes($i + 1)])->save();
    }
}

test('lista de fechamentos do terminal é paginada de 10 em 10, mais recentes primeiro', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch] = pdvContext();

    closedSessionsFor($company, $branch, $admin, '', 12);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('openClosingReports');

    $component->assertSee('CX-00')
        ->assertSee('CX-09')
        ->assertDontSee('CX-10')
        ->assertDontSee('CX-11');

    $component->set('paginators.closedSessionsPage', 2)
        ->assertSee('CX-10')
        ->assertSee('CX-11')
        ->assertDontSee('CX-09');
});

test('lista de fechamentos volta para a página 1 ao trocar de filial', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch] = pdvContext();

    $otherBranch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Outra Filial',
        'address' => 'Rua B, 2',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    closedSessionsFor($company, $branch, $admin, 'A', 12);
    closedSessionsFor($company, $otherBranch, $admin, 'B', 12);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('openClosingReports')
        ->set('paginators.closedSessionsPage', 2)
        ->assertSee('CX-A10');

    $component->set('selectedBranchId', $otherBranch->id)
        ->assertSee('CX-B00')
        ->assertDontSee('CX-B10')
        ->assertDontSee('CX-A');
});

test('reabrir o overlay de fechamentos começa na página 1', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch] = pdvContext();

    closedSessionsFor($company, $branch, $admin, '', 12);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('openClosingReports')
        ->set('paginators.closedSessionsPage', 2)
        ->assertSee('CX-10');

    $component->call('backFromClosingReports')
        ->call('openClosingReports')
        ->assertSee('CX-00')
        ->assertDontSee('CX-10');
});
