<?php

use App\Models\Company;
use App\Models\FiscalNote;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createFiscalNote(string $ref = 'ref_001', string $status = 'pending'): FiscalNote
{
    $company = Company::create([
        'name' => 'Empresa Webhook',
        'slug' => 'empresa-webhook-'.uniqid(),
        'order_prefix' => 'WHK',
        'active' => true,
        'status' => 'ACTIVE',
    ]);

    return FiscalNote::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'order_id' => null,
        'status' => $status,
        'provider_reference' => $ref,
    ]);
}

test('webhook fiscal atualiza status para authorized', function () {
    config(['fiscal.focus_nfe.webhook_token' => 'secret123']);

    $note = createFiscalNote('ref_wh_001');

    $this->postJson('/webhooks/fiscal', [
        'ref' => 'ref_wh_001',
        'evento' => 'autorizacao',
        'status' => 'autorizado',
        'chave_nfe' => str_repeat('1', 44),
        'caminho_danfe' => 'https://focus.test/danfe',
        'caminho_xml_nota_fiscal' => 'https://focus.test/xml',
    ], ['x-focus-nfe-token' => 'secret123'])
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    $note->refresh();
    expect($note->status)->toBe('authorized');
    expect($note->access_key)->toHaveLength(44);
    expect($note->data['danfe_url'])->toBe('https://focus.test/danfe');
});

test('webhook fiscal retorna 401 com token inválido', function () {
    config(['fiscal.focus_nfe.webhook_token' => 'secret123']);

    $this->postJson('/webhooks/fiscal', ['ref' => 'x'], ['x-focus-nfe-token' => 'wrong'])
        ->assertStatus(401);
});

test('webhook fiscal ignora ref desconhecida sem erro', function () {
    config(['fiscal.focus_nfe.webhook_token' => 'secret123']);

    $this->postJson('/webhooks/fiscal', [
        'ref' => 'ref_inexistente',
        'status' => 'autorizado',
    ], ['x-focus-nfe-token' => 'secret123'])
        ->assertOk()
        ->assertJson(['status' => 'ok']);
});
