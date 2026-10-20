<?php

/*
 * Auditoria 16/10/2026 — PDV Terminal: showClosingReports/viewingClosedSessionId eram
 * públicos (sem #[Locked]). openClosingReports()/viewClosedSession() barram o caixa, mas
 * ele podia setar as props direto no payload e ver a listagem de fechamentos da filial e
 * o fechamento (esperado, contado, justificativa) de outro operador. Os computeds
 * closedSessions/viewingClosedSession também não barravam o caixa nem filtravam a filial.
 */

use App\Livewire\Admin\Pdv\Terminal;
use App\Models\Branch;
use App\Models\PdvCashSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/TenantAuditHelpers.php';

uses(RefreshDatabase::class);

/** Caixa com sessão aberta e um fechamento de outro operador na mesma filial. */
function atnCaixaAndForeignClosing(): array
{
    ['company' => $company, 'branch' => $branch, 'admin' => $admin] = pdvContext();

    $caixa = User::factory()->create();
    $caixa->companies()->attach($company->id, ['role' => 'caixa', 'branch_id' => $branch->id]);

    PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $caixa->id,
        'opening_amount' => 0,
    ]);

    $otherSession = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => User::factory()->create()->id,
        'opening_amount' => 40.00,
        'closing_amount' => 1234.56,
        'expected_amount' => 1200.00,
        'terminal_name' => 'Terminal do outro caixa',
        'reconciliation_notes' => 'Justificativa sigilosa do outro caixa',
        'closed_at' => now(),
    ]);

    return compact('company', 'branch', 'admin', 'caixa', 'otherSession');
}

test('caixa não abre o fechamento de outro operador setando viewingClosedSessionId', function () {
    ['caixa' => $caixa, 'otherSession' => $otherSession] = atnCaixaAndForeignClosing();

    $html = atnAttempt(fn () => Livewire::actingAs($caixa)
        ->test(Terminal::class)
        ->set('showClosingReports', true)
        ->set('viewingClosedSessionId', $otherSession->id)
        ->html());

    expect((string) $html)
        ->not->toContain('Terminal do outro caixa')
        ->not->toContain('Justificativa sigilosa do outro caixa');
});

test('caixa não vê a listagem de fechamentos da filial setando showClosingReports', function () {
    ['caixa' => $caixa] = atnCaixaAndForeignClosing();

    $html = atnAttempt(fn () => Livewire::actingAs($caixa)
        ->test(Terminal::class)
        ->set('showClosingReports', true)
        ->html());

    expect((string) $html)
        ->not->toContain('Terminal do outro caixa')
        ->not->toContain('1.234,56');
});

test('computeds de fechamento não devolvem sessão de outro operador ao caixa nem de outra filial', function () {
    ['company' => $company, 'admin' => $admin, 'caixa' => $caixa, 'otherSession' => $otherSession] = atnCaixaAndForeignClosing();

    // Defesa em profundidade: mesmo com o id já no componente (ex.: setado no servidor),
    // o caixa não lê fechamento de outro operador nem a listagem.
    $component = Livewire::actingAs($caixa)->test(Terminal::class);
    $component->instance()->viewingClosedSessionId = $otherSession->id;

    expect($component->instance()->viewingClosedSession)->toBeNull()
        ->and($component->instance()->closedSessions)->toBeEmpty();

    // Operador sem trava de caixa: fechamento de outra filial não aparece fora da filial selecionada.
    $otherBranch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Outra filial',
        'address' => 'Rua B, 2',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);
    $otherBranchSession = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $otherBranch->id,
        'user_id' => User::factory()->create()->id,
        'opening_amount' => 0,
        'closing_amount' => 10,
        'expected_amount' => 10,
        'closed_at' => now(),
    ]);
    $adminComponent = Livewire::actingAs($admin)->test(Terminal::class);
    $adminInstance = $adminComponent->instance();
    expect($adminInstance->selectedBranchId)->not->toBe($otherBranch->id);

    $adminInstance->viewingClosedSessionId = $otherBranchSession->id;
    expect($adminInstance->viewingClosedSession)->toBeNull();
});
