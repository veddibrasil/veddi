<?php

use App\Enums\EmailCampaignStatus;
use App\Livewire\SuperAdmin\Marketing\Automations\Form;
use App\Livewire\SuperAdmin\Marketing\Automations\Index;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\EmailAutomation;
use App\Models\EmailAutomationEnrollment;
use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function abandonedSignupAutomation(): EmailAutomation
{
    $template = EmailTemplate::factory()->create();

    Livewire::actingAs(marketingSuperAdmin())->test(Form::class)
        ->set('name', 'Recuperar cadastro')
        ->set('trigger', 'abandoned_signup')
        ->set('threshold', 2)
        ->set('fromEmail', 'novidades@news.veddi.com.br')
        ->set('fromName', 'Veddi')
        ->set('steps', [
            ['id' => null, 'delay_hours' => 0, 'template_id' => $template->id, 'subject' => 'Falta pouco, {{primeiro_nome}}', 'preheader' => ''],
            ['id' => null, 'delay_hours' => 48, 'template_id' => $template->id, 'subject' => 'Ainda dá tempo', 'preheader' => ''],
        ])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    return EmailAutomation::with('steps.campaign')->firstOrFail();
}

test('salvar cria um passo por e-mail com campanha interna pausada', function () {
    marketingSetup();
    $automation = abandonedSignupAutomation();

    expect($automation->active)->toBeFalse()
        ->and($automation->steps)->toHaveCount(2)
        ->and($automation->steps->pluck('position')->all())->toBe([1, 2])
        ->and($automation->steps[0]->campaign->kind)->toBe(EmailCampaign::KIND_AUTOMATION)
        ->and($automation->steps[0]->campaign->status)->toBe(EmailCampaignStatus::Paused)
        ->and($automation->steps[0]->campaign->html_snapshot)->not->toBeNull()
        ->and(EmailCampaign::campaigns()->count())->toBe(0);
});

test('cadastro abandonado: entra, recebe a sequência e sai quando a empresa ativa', function () {
    $fake = marketingSetup();
    $automation = abandonedSignupAutomation();
    Livewire::actingAs(marketingSuperAdmin())->test(Index::class)->call('toggle', $automation->id)->assertSet('errorNotice', null);

    $lead = marketingMember(['status' => 'PENDING_PAYMENT', 'active' => false, 'name' => 'Lanches X'], user: ['email' => 'lead@a.com', 'name' => 'Bia Costa']);
    $lead->companies()->first()->forceFill(['created_at' => now()->subHours(3)])->save();
    $recente = marketingMember(['status' => 'PENDING_PAYMENT', 'active' => false], user: ['email' => 'agora@a.com']);
    marketingMember(['status' => 'ACTIVE'], user: ['email' => 'ativa@a.com']);

    $this->artisan('marketing:run-automations')->assertSuccessful();

    expect($fake->sentTo())->toBe(['lead@a.com'])
        ->and($fake->sent[0]->subject)->toBe('Falta pouco, Bia')
        ->and(EmailAutomationEnrollment::where('email', 'lead@a.com')->value('next_position'))->toBe(2);

    // Rodar de novo não reenvia o passo 1.
    $this->artisan('marketing:run-automations')->assertSuccessful();
    expect($fake->sent)->toHaveCount(1);

    // Empresa pagou: sai antes do passo 2.
    $lead->companies()->first()->update(['status' => 'ACTIVE']);
    $this->travel(49)->hours();
    $this->artisan('marketing:run-automations')->assertSuccessful();

    expect(EmailAutomationEnrollment::where('email', 'lead@a.com')->value('status'))->toBe(EmailAutomationEnrollment::STATUS_EXITED)
        ->and($fake->sentTo())->not->toContain('ativa@a.com');
});

test('empresa sem pedidos entra e sai quando volta a vender', function () {
    $fake = marketingSetup();
    $template = EmailTemplate::factory()->create();
    $automation = app(\App\Services\Marketing\AutomationService::class)->save(null, [
        'name' => 'Reativar', 'trigger' => 'inactive_company', 'trigger_settings' => ['threshold' => 30],
        'from_name' => 'Veddi', 'from_email' => 'novidades@news.veddi.com.br',
    ], [
        ['delay_hours' => 0, 'template_id' => $template->id, 'subject' => 'Sentimos sua falta'],
        ['delay_hours' => 72, 'template_id' => $template->id, 'subject' => 'Dica para vender mais'],
    ], marketingSuperAdmin());
    app(\App\Services\Marketing\AutomationService::class)->setActive($automation, true);

    $member = marketingMember(user: ['email' => 'parada@a.com']);
    $member->companies()->first()->forceFill(['created_at' => now()->subDays(40)])->save();
    marketingMember(user: ['email' => 'recem-criada@a.com']);

    $this->artisan('marketing:run-automations')->assertSuccessful();
    // Empresa criada agora também não tem pedidos, mas não é "inativa".
    expect($fake->sentTo())->toBe(['parada@a.com']);

    $company = $member->companies()->first();
    $branch = Branch::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Centro', 'active' => true]);
    $customer = Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'C', 'phone' => '11999990000']);
    $this->travel(1)->hours();
    Order::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'customer_id' => $customer->id, 'branch_id' => $branch->id,
        'subtotal' => 10, 'delivery_fee' => 0, 'total' => 10, 'status' => 'delivered',
        'payment_method' => 'pix', 'order_type' => 'delivery', 'fee' => 0, 'net_value' => 10,
    ]);

    $this->travel(73)->hours();
    $this->artisan('marketing:run-automations')->assertSuccessful();

    expect($fake->sent)->toHaveCount(1)
        ->and(EmailAutomationEnrollment::first()->status)->toBe(EmailAutomationEnrollment::STATUS_EXITED);
});

test('não ativa automação com remetente de domínio não verificado', function () {
    marketingSetup();
    config(['marketing.verified_domains' => ['outro.com.br']]);
    $automation = abandonedSignupAutomation();

    Livewire::actingAs(marketingSuperAdmin())->test(Index::class)
        ->call('toggle', $automation->id)
        ->assertSet('errorNotice', fn ($value) => str_contains((string) $value, 'Remetente de domínio verificado'));

    expect($automation->refresh()->active)->toBeFalse();
});

test('automação pausada não envia', function () {
    $fake = marketingSetup();
    abandonedSignupAutomation();
    $lead = marketingMember(['status' => 'PENDING_PAYMENT', 'active' => false]);
    $lead->companies()->first()->forceFill(['created_at' => now()->subHours(3)])->save();

    $this->artisan('marketing:run-automations')->assertSuccessful();

    expect($fake->sent)->toBeEmpty()->and(EmailAutomationEnrollment::count())->toBe(0);
});

test('ao sair da sequência, o e-mail do passo que ainda não saiu é cancelado', function () {
    marketingSetup();
    $automation = abandonedSignupAutomation();
    app(\App\Services\Marketing\AutomationService::class)->setActive($automation, true);
    $lead = marketingMember(['status' => 'PENDING_PAYMENT', 'active' => false], user: ['email' => 'lead@a.com']);
    $lead->companies()->first()->forceFill(['created_at' => now()->subHours(3)])->save();

    // Passo enfileirado mas ainda não enviado (ex.: limite diário do aquecimento).
    \Illuminate\Support\Facades\Queue::fake();
    $this->artisan('marketing:run-automations')->assertSuccessful();
    $pending = \App\Models\EmailCampaignRecipient::where('email', 'lead@a.com')->firstOrFail();
    expect($pending->status)->toBe(\App\Enums\EmailRecipientStatus::Pending);

    $lead->companies()->first()->update(['status' => 'ACTIVE']);
    $this->artisan('marketing:run-automations')->assertSuccessful();

    expect($pending->refresh()->status)->toBe(\App\Enums\EmailRecipientStatus::Cancelled);
});

test('editar automação ativa com remetente não verificado pausa a automação', function () {
    marketingSetup();
    $automation = abandonedSignupAutomation();
    app(\App\Services\Marketing\AutomationService::class)->setActive($automation, true);

    Livewire::actingAs(marketingSuperAdmin())->test(Form::class, ['automation' => $automation])
        ->set('fromEmail', 'promo@gmail.com')
        ->call('save')
        ->assertRedirect();

    expect($automation->refresh()->active)->toBeFalse();
});
