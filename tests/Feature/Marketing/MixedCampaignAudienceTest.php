<?php

use App\DTOs\AudienceFilterData;
use App\Enums\EmailCampaignStatus;
use App\Enums\EmailSuppressionReason;
use App\Livewire\SuperAdmin\Marketing\Campaigns\Wizard;
use App\Models\Company;
use App\Models\EmailCampaign;
use App\Models\EmailContact;
use App\Models\EmailContactList;
use App\Models\EmailSuppression;
use App\Services\Marketing\AudienceResolver;
use App\Services\Marketing\CampaignService;
use App\Services\Marketing\SuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => marketingSetup());

function mixedAudienceList(array $emails): EmailContactList
{
    $list = EmailContactList::create(['name' => 'Contatos do evento', 'source' => 'Formulário do evento', 'consent_confirmed_at' => now()]);
    foreach ($emails as $email) {
        EmailContact::create(['list_id' => $list->id, 'email' => $email, 'name' => 'Contato do evento']);
    }

    return $list;
}

test('lista com donos inclui os dois publicos e conta emails uma unica vez', function () {
    $owner = marketingMember(user: ['email' => 'Dono@empresa.com']);
    $other = Company::create(['name' => 'Outra empresa', 'slug' => 'outra-mista', 'order_prefix' => 'OUT', 'active' => true, 'plan' => 'pro', 'status' => 'ACTIVE']);
    $other->users()->attach($owner->id, ['role' => 'company_admin']);
    marketingMember(role: 'branch_manager', user: ['email' => 'gerente@empresa.com']);
    $list = mixedAudienceList(['dono@empresa.com', 'contato@empresa.com']);
    $duplicateList = mixedAudienceList(['contato@empresa.com']);
    $filters = new AudienceFilterData(contactLists: [$list->id, $duplicateList->id], includeOwners: true);
    $audience = app(AudienceResolver::class);

    expect($audience->count($filters))->toBe(2)
        ->and($audience->sample($filters)->pluck('email')->sort()->values()->all())->toBe(['contato@empresa.com', 'dono@empresa.com']);

    $rows = collect();
    $audience->chunk($filters, 100, function ($chunk) use (&$rows) {
        $rows = $rows->concat($chunk);
    });
    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('email', 'dono@empresa.com')->user_id)->toBe($owner->id)
        ->and($rows->firstWhere('email', 'dono@empresa.com')->contact_id)->toBeNull()
        ->and($rows->firstWhere('email', 'contato@empresa.com')->contact_id)->not->toBeNull();
});

test('copias importadas nao contornam consentimento descadastro ou supressao dos donos', function () {
    marketingMember(user: ['email' => 'ok@empresa.com']);
    marketingMember(user: ['email' => 'semaceite@empresa.com', 'marketing_opt_in_at' => null]);
    marketingMember(user: ['email' => 'saiu@empresa.com', 'marketing_opt_out_at' => now()]);
    marketingMember(user: ['email' => 'bloqueado@empresa.com']);
    EmailSuppression::create(['email' => 'bloqueado@empresa.com', 'reason' => EmailSuppressionReason::HardBounce]);
    $list = mixedAudienceList(['semaceite@empresa.com', 'saiu@empresa.com', 'bloqueado@empresa.com', 'externo@empresa.com']);

    $filters = new AudienceFilterData(contactLists: [$list->id], includeOwners: true);
    expect(app(AudienceResolver::class)->count($filters))->toBe(2)
        ->and(app(AudienceResolver::class)->sample($filters)->pluck('email')->sort()->values()->all())->toBe(['externo@empresa.com', 'ok@empresa.com']);
});

test('listas existentes continuam somente com contatos ate a inclusao dos donos ser selecionada', function () {
    marketingMember(user: ['email' => 'dono@empresa.com']);
    $list = mixedAudienceList(['externo@empresa.com']);
    $legacy = AudienceFilterData::fromArray(['contact_lists' => [$list->id]]);

    expect($legacy->includeOwners)->toBeFalse()
        ->and(app(AudienceResolver::class)->count($legacy))->toBe(1)
        ->and(app(AudienceResolver::class)->sample($legacy)->first()->email)->toBe('externo@empresa.com');
});

test('wizard salva lista mais donos reabre a escolha e permite desmarcar', function () {
    marketingMember(user: ['email' => 'dono@empresa.com']);
    $list = mixedAudienceList(['externo@empresa.com']);
    $campaign = EmailCampaign::factory()->create();

    Livewire::actingAs(marketingSuperAdmin())->test(Wizard::class, ['campaign' => $campaign])
        ->set('step', 2)
        ->set('audienceMode', 'contacts')
        ->set('includeOwners', true)
        ->assertSee('Incluir também os donos das empresas')
        ->assertSeeHtml('data-test="audience-count">0<')
        ->call('next')->assertHasErrors('contactListIds')->assertSet('step', 2)
        ->set('contactListIds', [$list->id])
        ->assertSeeHtml('data-test="audience-count">2<')
        ->call('next')->assertHasNoErrors()->assertSet('step', 3);

    expect($campaign->refresh()->filters['include_owners'])->toBeTrue();

    Livewire::actingAs(marketingSuperAdmin())->test(Wizard::class, ['campaign' => $campaign])
        ->assertSet('audienceMode', 'contacts')
        ->assertSet('contactListIds', [$list->id])
        ->assertSet('includeOwners', true)
        ->set('step', 2)
        ->set('includeOwners', false)
        ->assertSeeHtml('data-test="audience-count">1<')
        ->call('next')->assertHasNoErrors();

    expect($campaign->refresh()->filters['include_owners'])->toBeFalse();
});

test('segmento envia para administrador e email da empresa uma vez, e o descadastro vale para a proxima campanha', function () {
    $fake = marketingSetup();
    config(['marketing.require_opt_in' => false]);
    marketingMember(['email' => 'contato@loja.com'], user: ['email' => 'dono@empresa.com']);
    marketingMember(['model' => Company::create(['name' => 'Filial', 'slug' => 'filial-mista', 'order_prefix' => 'FIL', 'active' => true, 'plan' => 'pro', 'status' => 'ACTIVE', 'email' => 'contato@loja.com'])], user: ['email' => 'socio@empresa.com']);
    $campaign = EmailCampaign::factory()->create(['test_sent_at' => now(), 'filters' => []]);

    app(CampaignService::class)->start($campaign);

    expect($campaign->refresh()->status)->toBe(EmailCampaignStatus::Sent)
        ->and(collect($fake->sent)->pluck('toEmail')->sort()->values()->all())->toBe(['contato@loja.com', 'dono@empresa.com', 'socio@empresa.com']);

    $recipient = $campaign->recipients()->where('email', 'contato@loja.com')->first();
    app(SuppressionService::class)->unsubscribe($recipient);

    expect(app(AudienceResolver::class)->count(new AudienceFilterData))->toBe(2);
});

test('email da empresa nao sai se o aceite passar a ser exigido depois de montar o publico', function () {
    $fake = marketingSetup();
    config(['marketing.require_opt_in' => false]);
    marketingMember(['email' => 'contato@loja.com'], user: ['email' => 'dono@empresa.com']);
    $campaign = EmailCampaign::factory()->create(['filters' => []]);
    app(CampaignService::class)->addRecipients($campaign, app(AudienceResolver::class)->sample(new AudienceFilterData));
    config(['marketing.require_opt_in' => true]);

    $blocked = $campaign->recipients()->get()->mapWithKeys(fn ($recipient) => [$recipient->email => app(SuppressionService::class)->blocks($recipient)]);

    expect($blocked->all())->toEqual(['contato@loja.com' => true, 'dono@empresa.com' => false]);
});

test('disparo misto no provedor fake envia uma vez por email mesmo em blocos pequenos', function () {
    $fake = marketingSetup();
    config(['marketing.sending.build_chunk' => 1]);
    $owner = marketingMember(user: ['email' => 'dono@empresa.com']);
    $other = Company::create(['name' => 'Segunda', 'slug' => 'segunda-mista', 'order_prefix' => 'SEG', 'active' => true, 'plan' => 'pro', 'status' => 'ACTIVE']);
    $other->users()->attach($owner->id, ['role' => 'company_admin']);
    $list = mixedAudienceList(['dono@empresa.com', 'externo@empresa.com']);
    $duplicateList = mixedAudienceList(['externo@empresa.com']);
    $campaign = EmailCampaign::factory()->create([
        'test_sent_at' => now(),
        'filters' => ['contact_lists' => [$list->id, $duplicateList->id], 'include_owners' => true],
    ]);

    app(CampaignService::class)->start($campaign);

    expect($campaign->refresh()->status)->toBe(EmailCampaignStatus::Sent)
        ->and($campaign->recipients_count)->toBe(2)
        ->and(collect($fake->sent)->pluck('toEmail')->sort()->values()->all())->toBe(['dono@empresa.com', 'externo@empresa.com']);
});
