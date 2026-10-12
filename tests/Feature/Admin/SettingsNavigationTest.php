<?php

use App\Livewire\Admin\Branches\Form;
use App\Livewire\Admin\Branches\Pauses;
use App\Livewire\Admin\Branches\ServiceCharges;
use App\Livewire\Admin\Fiscal\Config as FiscalConfig;
use App\Livewire\Admin\Integrations\Index as IntegrationsIndex;
use App\Livewire\Admin\Settings\BillingSettings;
use App\Livewire\Admin\Settings\CompanySettings;
use App\Livewire\Admin\Settings\IfoodIntegrationSettings;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function snCompany(array $overrides = []): Company
{
    $company = Company::create(array_merge([
        'name' => 'Config Teste '.uniqid(),
        'slug' => 'config-teste-'.uniqid(),
        'order_prefix' => 'CFG',
        'active' => true,
        'plan' => 'pro',
        'primary_color' => '#7A00A3',
        'primary_color_dark' => '#5c0079',
        'primary_color_light' => '#9B10C8',
        'secondary_color' => '#1E2F4F',
        'secondary_color_light' => '#D8DEE9',
        'accent_color' => '#f9f0fd',
    ], $overrides));

    app()->instance('current.company', $company);

    return $company;
}

function snBranch(Company $company, string $name = 'Filial Centro'): Branch
{
    return Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => $name,
        'address' => 'Rua A, 1',
        'city' => 'Maringá',
        'state' => 'PR',
        'active' => true,
    ]);
}

function snAdmin(Company $company): User
{
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    return $admin;
}

function snGrant(User $user, Company $company, string ...$permissions): void
{
    foreach ($permissions as $name) {
        $permission = Permission::firstOrCreate(['name' => $name], ['group' => 'fiscal', 'label' => $name]);

        UserPermission::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'permission_id' => $permission->id,
            'granted' => true,
        ]);
    }
}

function snManager(Company $company, Branch $branch): User
{
    $manager = User::factory()->create();
    $manager->companies()->attach($company->id, ['role' => 'branch_manager', 'branch_id' => $branch->id]);

    return $manager;
}

// ─── Sidebar e menu do usuário ──────────────────────────────────────────────

test('sidebar separa Financeiro (assinatura e carteira) de Configurações', function () {
    $company = snCompany();
    snBranch($company);

    $html = $this->actingAs(snAdmin($company))
        ->get(route('admin.settings'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('>Financeiro<')->toContain('>Assinatura<')->toContain('>Carteira<');
    expect(strpos($html, '>Financeiro<'))->toBeLessThan(strpos($html, '>Configurações<'));
    expect(strpos($html, '>Assinatura<'))->toBeLessThan(strpos($html, '>Configurações<'));
    expect($html)->not->toContain('Yapay');
});

test('carteira abre na mesma aba: é uma tela interna do painel', function () {
    $company = snCompany();
    snBranch($company);

    $html = $this->actingAs(snAdmin($company))->get(route('admin.settings'))->getContent();

    preg_match('/<a[^>]*href="'.preg_quote(route('admin.wallet'), '/').'"[^>]*>/', $html, $wallet);

    expect($wallet)->not->toBeEmpty();
    expect($wallet[0])->not->toContain('target="_blank"');
});

test('menu do usuário chama de "Minha conta" o que era "Configurações" (perfil, senha, 2FA)', function () {
    $company = snCompany();
    snBranch($company);

    $this->actingAs(snAdmin($company))
        ->get(route('admin.settings'))
        ->assertOk()
        ->assertSee('Minha conta');
});

test('item fiscal da sidebar não se chama Configurações', function () {
    $company = snCompany(['fiscal_notes_enabled' => true]);
    snBranch($company);

    $admin = snAdmin($company);
    snGrant($admin, $company, 'fiscal.view', 'fiscal.settings');

    $html = $this->actingAs($admin)->get(route('admin.settings'))->getContent();

    expect($html)->toContain('>Dados fiscais<')->toContain('>Notas Fiscais<');
    // "Configurações" sobra só como título do grupo da empresa (1x na sidebar).
    expect(substr_count($html, '>Configurações<'))->toBe(1);
});

// ─── Links entre as telas ───────────────────────────────────────────────────

test('assinatura leva o módulo fiscal ativo para os dados fiscais, não para a empresa', function () {
    $company = snCompany(['fiscal_notes_enabled' => true]);
    snBranch($company);

    $html = Livewire::actingAs(snAdmin($company))
        ->test(BillingSettings::class)
        ->assertOk()
        ->html();

    expect($html)->toContain('href="'.route('admin.fiscal.config').'"');
});

test('empresa aponta para os horários de agendamento da filial e a filial devolve o link', function () {
    $company = snCompany();
    $branch = snBranch($company);
    $admin = snAdmin($company);

    Livewire::actingAs($admin)
        ->test(CompanySettings::class)
        ->assertSeeHtml('id="agendamento"')
        ->assertSee('Filiais › Dados e horários')
        ->assertSeeHtml('href="'.route('admin.branches.index').'"');

    Livewire::actingAs($admin)
        ->test(Form::class, ['branch' => $branch])
        ->assertSeeHtml('href="'.route('admin.settings').'#agendamento"');
});

test('gerente não recebe link para configurações da empresa que ele não pode abrir', function () {
    $company = snCompany();
    $branch = snBranch($company);

    Livewire::actingAs(snManager($company, $branch))
        ->test(Form::class, ['branch' => $branch])
        ->assertOk()
        ->assertDontSeeHtml('#agendamento');
});

test('breadcrumb da filial usa o mesmo rótulo da sidebar: "Minha filial" para gerente, "Filiais" para admin', function () {
    $company = snCompany();
    $branch = snBranch($company);

    Livewire::actingAs(snManager($company, $branch))
        ->test(Pauses::class, ['branch' => $branch])
        ->assertSeeHtml('>Minha filial</a>');

    Livewire::actingAs(snAdmin($company))
        ->test(Pauses::class, ['branch' => $branch])
        ->assertSeeHtml('>Filiais</a>');
});

test('edição de filial tem um caminho de volta só (breadcrumb); criação mantém a seta', function () {
    $company = snCompany();
    $branch = snBranch($company);
    $admin = snAdmin($company);

    Livewire::actingAs($admin)->test(Form::class, ['branch' => $branch])
        ->assertSeeHtml('aria-label="Localização"')
        ->assertDontSeeHtml('aria-label="Voltar para filiais"');

    Livewire::actingAs($admin)->test(Form::class)
        ->assertSeeHtml('aria-label="Voltar para filiais"');
});

test('tela do iFood volta para Integrações e a lista usa ícone, não emoji', function () {
    $company = snCompany();
    snBranch($company);
    $admin = snAdmin($company);

    Livewire::actingAs($admin)
        ->test(IfoodIntegrationSettings::class)
        ->assertSeeHtml('href="'.route('admin.integrations.index').'"')
        ->assertSeeHtml('aria-label="Voltar para integrações"');

    Livewire::actingAs($admin)
        ->test(IntegrationsIndex::class)
        ->assertDontSee('🛵')
        ->assertSeeHtml('focus-visible:outline');
});

// ─── Padrão das telas de configuração ───────────────────────────────────────

test('telas de configuração avisam antes de descartar edições não salvas', function () {
    $company = snCompany(['fiscal_notes_enabled' => true, 'pdv_module_enabled' => true]);
    $branch = snBranch($company);
    $admin = snAdmin($company);

    Livewire::actingAs($admin)->test(CompanySettings::class)->assertSee('Você tem alterações não salvas');
    Livewire::actingAs($admin)->test(Form::class, ['branch' => $branch])->assertSee('Você tem alterações não salvas');
    Livewire::actingAs($admin)->test(ServiceCharges::class, ['branch' => $branch])->assertSee('Você tem alterações não salvas');
    Livewire::actingAs($admin)->test(FiscalConfig::class)->assertSee('Você tem alterações não salvas');
});

test('configurações da empresa: salvar fica fixo na base e cada cor tem label ligado ao campo', function () {
    $company = snCompany();
    snBranch($company);

    Livewire::actingAs(snAdmin($company))
        ->test(CompanySettings::class)
        ->assertSeeHtml('sticky bottom-0')
        ->assertSeeHtml('for="color-primary_color"')
        ->assertSeeHtml('id="color-primary_color"')
        ->assertSeeHtml('aria-label="Cor primária (código hexadecimal)"')
        ->assertSeeHtml('for="color-accent_color"');
});

test('dados fiscais: título igual ao da sidebar', function () {
    $company = snCompany(['fiscal_notes_enabled' => true]);
    snBranch($company);

    Livewire::actingAs(snAdmin($company))
        ->test(FiscalConfig::class)
        ->assertSee('Dados fiscais')
        ->assertDontSee('Configurações Fiscais');
});

test('telas de configuração não usam text-neutral-400 em texto (contraste AA)', function () {
    $files = array_merge(
        glob(resource_path('views/livewire/admin/settings/*.blade.php')),
        glob(resource_path('views/livewire/admin/branches/*.blade.php')),
        glob(resource_path('views/components/admin/*.blade.php')),
        [
            resource_path('views/livewire/admin/fiscal/config.blade.php'),
            resource_path('views/livewire/admin/integrations/index.blade.php'),
        ],
    );

    $offenders = [];
    foreach ($files as $file) {
        foreach (file($file) as $i => $line) {
            if (preg_match('/(?<![\w:-])text-neutral-400(?![\w-])/', $line)) {
                $offenders[] = basename($file).':'.($i + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
