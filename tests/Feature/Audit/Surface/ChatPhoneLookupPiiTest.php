<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Drawer\Utils;

uses(RefreshDatabase::class);

/**
 * Auditoria 15/10 (superfície) — chat público identifica o cliente só pelo telefone.
 *
 * Qualquer visitante anônimo digita um telefone em /{empresa} e o servidor devolve, nas
 * propriedades públicas do Livewire (snapshot JSON), nome, e-mail, endereço e CPF do cliente.
 * Se o telefone não existir na empresa, CustomerService::findFromGlobal() busca em TODAS as
 * empresas da plataforma e copia o cadastro para a empresa do chat (createFromGlobal).
 * Sem rate limit em submitPhone (/livewire/update não tem throttle).
 */
function piiLookupCompany(string $name): Company
{
    $company = Company::create([
        'name' => $name,
        'slug' => str($name)->slug().'-'.uniqid(),
        'order_prefix' => strtoupper(substr(str($name)->slug()->replace('-', ''), 0, 3)),
        'active' => true,
        'status' => 'ACTIVE',
        'email' => 'contato@'.str($name)->slug().'.com',
    ]);

    Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    return $company;
}

function piiLookupSubmitPhone(\Tests\TestCase $test, Company $company, string $phone): array
{
    $html = $test->get('/'.$company->slug)->assertOk()->getContent();
    $snapshot = Utils::extractAttributeDataFromHtml($html, 'wire:snapshot');

    expect($snapshot['memo']['name'] ?? null)->toBe('chat.order-chat');

    $response = $test->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'calls' => [
                ['path' => '', 'method' => 'backToIdentifyPhone', 'params' => []],
                ['path' => '', 'method' => 'submitPhone', 'params' => []],
            ],
            'updates' => ['phone' => $phone],
        ]],
    ])->assertOk();

    return json_decode($response->json('components.0.snapshot'), true)['data'];
}

test('visitante anônimo não obtém CPF/e-mail/endereço de cliente de OUTRA empresa só digitando o telefone', function () {
    $victimStore = piiLookupCompany('Loja Vitima');
    $attackerStore = piiLookupCompany('Loja Atacante');

    Customer::withoutGlobalScopes()->create([
        'company_id' => $victimStore->id,
        'name' => 'Maria Vitima',
        'phone' => '11987654321',
        'email' => 'maria.vitima@example.com',
        'tax_id' => '52998224725',
        'address' => 'Rua Secreta',
        'number' => '42',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'cep' => '01001000',
    ]);

    $data = piiLookupSubmitPhone($this, $attackerStore, '(11) 98765-4321');

    // Observado hoje: o snapshot devolvido ao navegador traz os dados da cliente da outra empresa.
    expect($data['taxId'] ?? '')->not->toBe('52998224725')
        ->and($data['email'] ?? '')->not->toBe('maria.vitima@example.com')
        ->and($data['address'] ?? '')->not->toBe('Rua Secreta');

    // E o cadastro foi copiado para a empresa do atacante (fica no painel dele para sempre).
    expect(Customer::withoutGlobalScopes()
        ->where('company_id', $attackerStore->id)
        ->where('tax_id', '52998224725')
        ->exists())->toBeFalse();
});

test('visitante anônimo não obtém CPF/e-mail de cliente da própria loja só com o telefone (sem verificação)', function () {
    $store = piiLookupCompany('Loja Unica');

    Customer::withoutGlobalScopes()->create([
        'company_id' => $store->id,
        'name' => 'Joao Cliente',
        'phone' => '11912345678',
        'email' => 'joao@example.com',
        'tax_id' => '11144477735',
        'address' => 'Av. Privada',
        'number' => '7',
    ]);

    $data = piiLookupSubmitPhone($this, $store, '11912345678');

    expect($data['taxId'] ?? '')->not->toBe('11144477735')
        ->and($data['email'] ?? '')->not->toBe('joao@example.com');
});

test('enumeração por telefone não tem limite de tentativas', function () {
    $store = piiLookupCompany('Loja Enumerada');

    Customer::withoutGlobalScopes()->create([
        'company_id' => $store->id,
        'name' => 'Cliente Alvo da Enumeracao',
        'phone' => '11955554444',
    ]);

    $html = $this->get('/'.$store->slug)->assertOk()->getContent();
    $snapshot = Utils::extractAttributeDataFromHtml($html, 'wire:snapshot');

    $lookup = fn (string $phone) => $this->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'calls' => [
                ['path' => '', 'method' => 'backToIdentifyPhone', 'params' => []],
                ['path' => '', 'method' => 'submitPhone', 'params' => []],
            ],
            'updates' => ['phone' => $phone],
        ]],
    ]);

    // 40 telefones "chutados" em sequência, do mesmo IP e da mesma sessão.
    foreach (range(1, 40) as $i) {
        $lookup('119'.str_pad((string) $i, 8, '0', STR_PAD_LEFT));
    }

    $last = $lookup('11955554444');

    // Esperado: depois de dezenas de consultas o servidor já recusa (429) ou não revela mais o cadastro.
    $revealed = $last->status() === 200
        && (json_decode($last->json('components.0.snapshot'), true)['data']['name'] ?? '') === 'Cliente Alvo da Enumeracao';

    expect($revealed)->toBeFalse();
});

test('visitante anônimo não altera o cadastro (nome/endereço) de cliente existente só sabendo o telefone', function () {
    $store = piiLookupCompany('Loja Perfil');

    $victim = Customer::withoutGlobalScopes()->create([
        'company_id' => $store->id,
        'name' => 'Ana Original',
        'phone' => '11933332222',
        'address' => 'Rua Verdadeira',
        'number' => '10',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'cep' => '01001000',
    ]);

    $html = $this->get('/'.$store->slug)->assertOk()->getContent();
    $snapshot = Utils::extractAttributeDataFromHtml($html, 'wire:snapshot');

    $first = $this->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'calls' => [
                ['path' => '', 'method' => 'backToIdentifyPhone', 'params' => []],
                ['path' => '', 'method' => 'submitPhone', 'params' => []],
                ['path' => '', 'method' => 'openEditProfile', 'params' => []],
            ],
            'updates' => ['phone' => '11933332222'],
        ]],
    ])->assertOk();

    $this->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => $first->json('components.0.snapshot'),
            'calls' => [['path' => '', 'method' => 'submitEditProfile', 'params' => []]],
            'updates' => [
                'name' => 'Nome Trocado Pelo Atacante',
                'address' => 'Rua do Atacante',
                'number' => '666',
                'neighborhood' => 'Outro',
                'city' => 'Outra Cidade',
                'cep' => '99999-999',
            ],
        ]],
    ])->assertOk();

    $victim->refresh();

    expect($victim->name)->toBe('Ana Original')
        ->and($victim->address)->toBe('Rua Verdadeira');
});
