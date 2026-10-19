<?php

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Drawer\Utils;

uses(RefreshDatabase::class);

/**
 * Auditoria 15/10 (superfície) — OrderChat::mount() resolve a empresa por slug sem olhar
 * status/active (Company::where('slug', $slug)->first()). Empresa BLOCKED (inadimplente ou
 * bloqueada pelo super admin) não entra no /admin, mas o chat público segue vendendo.
 */
test('chat público de empresa BLOCKED não aceita pedidos', function (string $status) {
    $company = Company::create([
        'name' => 'Empresa Bloqueada Chat',
        'slug' => 'bloqueada-chat-'.uniqid(),
        'order_prefix' => 'BLC',
        'active' => false,
        'status' => $status,
        'email' => 'contato@bloqueada.com',
    ]);

    Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $response = $this->get('/'.$company->slug);

    if ($response->status() !== 200) {
        expect($response->status())->toBeIn([403, 404, 410]);

        return;
    }

    $step = Utils::extractAttributeDataFromHtml($response->getContent(), 'wire:snapshot')['data']['step'] ?? null;

    expect($step)->toBe('UNAVAILABLE');
})->with(['BLOCKED', 'PENDING_PAYMENT']);
