<?php

use App\DTOs\AudienceFilterData;
use App\Enums\EmailSuppressionReason;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\EmailSuppression;
use App\Models\Order;
use App\Services\Marketing\AudienceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => marketingSetup());

function audienceEmails(AudienceFilterData $filters): array
{
    $emails = [];
    app(AudienceResolver::class)->chunk($filters, 100, function ($rows) use (&$emails) {
        foreach ($rows as $row) {
            $emails[] = strtolower($row->email);
        }
    });
    sort($emails);

    return $emails;
}

test('filtro de plano, status e ativa retorna só quem deve', function () {
    $pro = marketingMember(['plan' => 'pro', 'status' => 'ACTIVE'], user: ['email' => 'pro@a.com']);
    $free = marketingMember(['plan' => 'free', 'status' => 'ACTIVE'], user: ['email' => 'free@a.com']);
    $pending = marketingMember(['plan' => 'essencial', 'status' => 'PENDING_PAYMENT', 'active' => false], user: ['email' => 'lead@a.com']);

    expect(audienceEmails(new AudienceFilterData(plans: ['pro'])))->toBe(['pro@a.com'])
        ->and(audienceEmails(new AudienceFilterData(companyStatuses: ['PENDING_PAYMENT'])))->toBe(['lead@a.com'])
        ->and(audienceEmails(new AudienceFilterData(companyActive: true)))->toBe(['free@a.com', 'pro@a.com'])
        ->and(audienceEmails(new AudienceFilterData))->toBe(['free@a.com', 'lead@a.com', 'pro@a.com']);
});

test('filtro de papel separa dono e gerente; outros papéis nunca entram', function () {
    marketingMember(role: 'company_admin', user: ['email' => 'dono@a.com']);
    marketingMember(role: 'branch_manager', user: ['email' => 'gerente@a.com']);
    marketingMember(role: 'garcom', user: ['email' => 'garcom@a.com']);

    expect(audienceEmails(new AudienceFilterData(roles: ['branch_manager'])))->toBe(['gerente@a.com'])
        ->and(audienceEmails(new AudienceFilterData))->toBe(['dono@a.com', 'gerente@a.com']);
});

test('filtro de módulos PDV e nota fiscal', function () {
    marketingMember(['pdv_module_enabled' => true, 'fiscal_notes_enabled' => false], user: ['email' => 'pdv@a.com']);
    marketingMember(['pdv_module_enabled' => false, 'fiscal_notes_enabled' => true], user: ['email' => 'nf@a.com']);

    expect(audienceEmails(new AudienceFilterData(pdvEnabled: true)))->toBe(['pdv@a.com'])
        ->and(audienceEmails(new AudienceFilterData(fiscalEnabled: true)))->toBe(['nf@a.com'])
        ->and(audienceEmails(new AudienceFilterData(pdvEnabled: false)))->toBe(['nf@a.com']);
});

test('filtro de data de cadastro da empresa', function () {
    $old = marketingMember(user: ['email' => 'antiga@a.com']);
    $old->companies()->first()->forceFill(['created_at' => now()->subMonths(6)])->save();
    marketingMember(user: ['email' => 'nova@a.com']);

    expect(audienceEmails(new AudienceFilterData(createdFrom: now()->subMonth()->toDateString())))->toBe(['nova@a.com'])
        ->and(audienceEmails(new AudienceFilterData(createdTo: now()->subMonths(2)->toDateString())))->toBe(['antiga@a.com']);
});

test('filtro sem pedidos há N dias considera só pedidos da própria empresa e empresas com essa idade', function () {
    $active = marketingMember(user: ['email' => 'vende@a.com']);
    $stopped = marketingMember(user: ['email' => 'parada@a.com']);
    marketingMember(user: ['email' => 'recem-criada@a.com']);

    foreach ([$active, $stopped] as $member) {
        $member->companies()->first()->forceFill(['created_at' => now()->subMonths(2)])->save();
    }

    $company = $active->companies()->first();
    $branch = Branch::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Centro', 'active' => true]);
    $customer = Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Cliente', 'phone' => '11999999999']);
    Order::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'customer_id' => $customer->id, 'branch_id' => $branch->id,
        'subtotal' => 10, 'delivery_fee' => 0, 'total' => 10, 'status' => 'delivered',
        'payment_method' => 'pix', 'order_type' => 'delivery', 'fee' => 0, 'net_value' => 10,
    ]);

    expect(audienceEmails(new AudienceFilterData(inactiveDays: 30)))->toBe(['parada@a.com']);
});

test('supressão, opt-out e falta de opt-in ficam de fora', function () {
    marketingMember(user: ['email' => 'ok@a.com']);
    marketingMember(user: ['email' => 'Suprimido@A.com']);
    marketingMember(user: ['email' => 'saiu@a.com', 'marketing_opt_out_at' => now()]);
    marketingMember(user: ['email' => 'semaceite@a.com', 'marketing_opt_in_at' => null]);
    EmailSuppression::create(['email' => 'suprimido@a.com', 'reason' => EmailSuppressionReason::HardBounce]);

    expect(audienceEmails(new AudienceFilterData))->toBe(['ok@a.com'])
        ->and(app(AudienceResolver::class)->count(new AudienceFilterData))->toBe(1);
});

test('com legítimo interesse quem não deu aceite entra, mas quem saiu continua fora', function () {
    config(['marketing.require_opt_in' => false]);
    marketingMember(user: ['email' => 'semaceite@a.com', 'marketing_opt_in_at' => null]);
    marketingMember(user: ['email' => 'saiu@a.com', 'marketing_opt_out_at' => now()]);

    expect(audienceEmails(new AudienceFilterData))->toBe(['semaceite@a.com']);
});

test('mesmo e-mail em duas empresas vira um destinatário', function () {
    $user = marketingMember(user: ['email' => 'socio@a.com']);
    $other = Company::create(['name' => 'Outra', 'slug' => 'outra-'.uniqid(), 'order_prefix' => 'OUT', 'active' => true, 'plan' => 'free', 'status' => 'ACTIVE']);
    $other->users()->attach($user->id, ['role' => 'company_admin']);

    expect(app(AudienceResolver::class)->count(new AudienceFilterData))->toBe(1)
        ->and(audienceEmails(new AudienceFilterData))->toBe(['socio@a.com']);
});

test('clientes finais dos restaurantes nunca entram no público', function () {
    $member = marketingMember(user: ['email' => 'dono@a.com']);
    Customer::withoutGlobalScopes()->create([
        'company_id' => $member->companies()->first()->id, 'name' => 'Cliente', 'phone' => '11988887777', 'email' => 'cliente@final.com',
    ]);

    expect(audienceEmails(new AudienceFilterData))->toBe(['dono@a.com']);
});

test('filtros fora da lista fechada são descartados', function () {
    $filters = AudienceFilterData::fromArray([
        'plans' => ['pro', "x' OR 1=1 --"],
        'roles' => ['company_admin', 'garcom'],
        'company_statuses' => ['ACTIVE', 'DROP'],
        'inactive_days' => '-5',
        'created_from' => '2026-13-99; drop',
    ]);

    expect($filters->plans)->toBe(['pro'])
        ->and($filters->roles)->toBe(['company_admin'])
        ->and($filters->companyStatuses)->toBe(['ACTIVE'])
        ->and($filters->inactiveDays)->toBeNull()
        ->and($filters->createdFrom)->toBeNull();
});
