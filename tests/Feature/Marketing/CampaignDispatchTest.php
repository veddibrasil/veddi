<?php

use App\DTOs\AudienceFilterData;
use App\Enums\EmailCampaignStatus;
use App\Enums\EmailRecipientStatus;
use App\Enums\EmailSuppressionReason;
use App\Exceptions\EmailProviderException;
use App\Jobs\BuildCampaignRecipients;
use App\Jobs\DispatchCampaignBatch;
use App\Jobs\SendCampaignEmail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailSuppression;
use App\Services\Marketing\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function readyCampaign(array $attributes = []): EmailCampaign
{
    return EmailCampaign::factory()->create($attributes + ['test_sent_at' => now()]);
}

test('disparo completo: monta público, envia uma vez para cada um e conclui', function () {
    $fake = marketingSetup();
    marketingMember(user: ['email' => 'a@empresa.com', 'name' => 'Ana Lima']);
    marketingMember(user: ['email' => 'b@empresa.com']);
    $campaign = readyCampaign();

    expect(app(CampaignService::class)->start($campaign))->toBeTrue();

    $campaign->refresh();
    expect($campaign->status)->toBe(EmailCampaignStatus::Sent)
        ->and($campaign->recipients_count)->toBe(2)
        ->and($campaign->sent_count)->toBe(2)
        ->and($campaign->finished_at)->not->toBeNull()
        ->and($campaign->html_snapshot)->not->toBeNull()
        ->and($fake->sentTo())->toEqualCanonicalizing(['a@empresa.com', 'b@empresa.com']);

    $ana = collect($fake->sent)->firstWhere('toEmail', 'a@empresa.com');
    expect($ana->html)->toContain('Oi, Ana')
        ->and(EmailCampaignRecipient::where('email', 'a@empresa.com')->first()->provider_message_id)->not->toBeNull();
});

test('disparo exige checklist completo (sem teste enviado não sai)', function () {
    marketingSetup();
    marketingMember();
    $campaign = EmailCampaign::factory()->create(['test_sent_at' => null]);

    expect(fn () => app(CampaignService::class)->start($campaign))->toThrow(LogicException::class);
    expect($campaign->refresh()->status)->toBe(EmailCampaignStatus::Draft);
});

test('checklist bloqueia remetente de domínio não verificado e rodapé sem CNPJ', function () {
    marketingSetup();
    config(['marketing.legal.document' => null]);
    marketingMember();
    $campaign = readyCampaign(['from_email' => 'promo@gmail.com']);

    $failed = collect(app(CampaignService::class)->checklist($campaign))->reject(fn ($item) => $item['ok'])->pluck('key')->all();

    expect($failed)->toEqualCanonicalizing(['sender', 'legal']);
});

test('dois cliques em disparar iniciam uma vez só', function () {
    marketingSetup();
    Queue::fake();
    marketingMember();
    $campaign = readyCampaign();

    $service = app(CampaignService::class);
    expect($service->start($campaign))->toBeTrue()
        ->and($service->start(EmailCampaign::find($campaign->id)))->toBeFalse();

    Queue::assertPushed(BuildCampaignRecipients::class, 1);
});

test('rodar BuildCampaignRecipients duas vezes não duplica destinatários', function () {
    marketingSetup();
    Queue::fake();
    marketingMember(user: ['email' => 'a@empresa.com']);
    marketingMember(user: ['email' => 'b@empresa.com']);
    $campaign = readyCampaign(['status' => EmailCampaignStatus::Building]);

    app()->call([new BuildCampaignRecipients($campaign->id), 'handle']);
    $campaign->forceFill(['status' => EmailCampaignStatus::Building])->save();
    app()->call([new BuildCampaignRecipients($campaign->id), 'handle']);

    expect(EmailCampaignRecipient::where('campaign_id', $campaign->id)->count())->toBe(2)
        ->and($campaign->refresh()->status)->toBe(EmailCampaignStatus::Sending);
});

test('SendCampaignEmail repetido não reenvia', function () {
    $fake = marketingSetup();
    $campaign = EmailCampaign::factory()->sending()->create();
    $recipient = EmailCampaignRecipient::factory()->for($campaign, 'campaign')->status(EmailRecipientStatus::Queued)->create();

    app()->call([new SendCampaignEmail($recipient->id), 'handle']);
    app()->call([new SendCampaignEmail($recipient->id), 'handle']);

    expect($fake->sent)->toHaveCount(1)
        ->and($recipient->refresh()->status)->toBe(EmailRecipientStatus::Sent)
        ->and($campaign->refresh()->sent_count)->toBe(1);
});

test('destinatário preso em sending não é reenviado: vira falha', function () {
    $fake = marketingSetup();
    Queue::fake([SendCampaignEmail::class]);
    $campaign = EmailCampaign::factory()->sending()->create();
    $stuck = EmailCampaignRecipient::factory()->for($campaign, 'campaign')->status(EmailRecipientStatus::Sending)->create();
    EmailCampaignRecipient::whereKey($stuck->id)->update(['updated_at' => now()->subHour()]);

    app()->call([new DispatchCampaignBatch($campaign->id), 'handle']);

    expect($stuck->refresh()->status)->toBe(EmailRecipientStatus::Failed)
        ->and($fake->sent)->toBeEmpty();
});

test('pausa interrompe os lotes seguintes e retomar continua de onde parou', function () {
    $fake = marketingSetup();
    config(['marketing.sending.batch_size' => 2]);
    $campaign = EmailCampaign::factory()->sending()->create();
    EmailCampaignRecipient::factory()->count(5)->for($campaign, 'campaign')->create();

    Queue::fake([SendCampaignEmail::class, DispatchCampaignBatch::class]);
    app()->call([new DispatchCampaignBatch($campaign->id), 'handle']);
    Queue::assertPushed(SendCampaignEmail::class, 2);

    $service = app(CampaignService::class);
    expect($service->pause($campaign))->toBeTrue();

    // Envio já na fila encontra a campanha pausada: volta para pending, sem enviar.
    $queued = EmailCampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'queued')->first();
    app()->call([new SendCampaignEmail($queued->id), 'handle']);
    expect($queued->refresh()->status)->toBe(EmailRecipientStatus::Pending)->and($fake->sent)->toBeEmpty();

    // Próximo lote não sai enquanto pausada.
    app()->call([new DispatchCampaignBatch($campaign->id), 'handle']);
    Queue::assertPushed(SendCampaignEmail::class, 2);

    Queue::fake();
    expect($service->resume($campaign->refresh()))->toBeTrue();
    expect($campaign->refresh()->status)->toBe(EmailCampaignStatus::Sending);
    Queue::assertPushed(DispatchCampaignBatch::class);
});

test('cancelamento não envia mais nada', function () {
    $fake = marketingSetup();
    $campaign = EmailCampaign::factory()->sending()->create();
    $queued = EmailCampaignRecipient::factory()->for($campaign, 'campaign')->status(EmailRecipientStatus::Queued)->create();
    EmailCampaignRecipient::factory()->count(3)->for($campaign, 'campaign')->create();

    expect(app(CampaignService::class)->cancel($campaign))->toBeTrue();

    app()->call([new SendCampaignEmail($queued->id), 'handle']);
    app()->call([new DispatchCampaignBatch($campaign->id), 'handle']);

    expect($fake->sent)->toBeEmpty()
        ->and($campaign->refresh()->status)->toBe(EmailCampaignStatus::Cancelled)
        ->and(EmailCampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'cancelled')->count())->toBe(4);
});

test('suprimido depois de montar o público não recebe (checagem antes do envio)', function () {
    $fake = marketingSetup();
    $campaign = EmailCampaign::factory()->sending()->create();
    $recipient = EmailCampaignRecipient::factory()->for($campaign, 'campaign')->status(EmailRecipientStatus::Queued)->create(['email' => 'saiu@a.com']);
    EmailSuppression::create(['email' => 'saiu@a.com', 'reason' => EmailSuppressionReason::Unsubscribed]);

    app()->call([new SendCampaignEmail($recipient->id), 'handle']);

    expect($fake->sent)->toBeEmpty()
        ->and($recipient->refresh()->status)->toBe(EmailRecipientStatus::Suppressed);
});

test('falha permanente marca failed sem retry; temporária volta para a fila', function () {
    $fake = marketingSetup();
    $campaign = EmailCampaign::factory()->sending()->create();
    $permanent = EmailCampaignRecipient::factory()->for($campaign, 'campaign')->status(EmailRecipientStatus::Queued)->create(['email' => 'nao.existe@a.com']);
    $temporary = EmailCampaignRecipient::factory()->for($campaign, 'campaign')->status(EmailRecipientStatus::Queued)->create(['email' => 'lento@a.com']);
    $fake->failures['nao.existe@a.com'] = EmailProviderException::permanent('Resend 422: invalid to');
    $fake->failures['lento@a.com'] = EmailProviderException::temporary('Resend 503');

    app()->call([new SendCampaignEmail($permanent->id), 'handle']);
    app()->call([new SendCampaignEmail($temporary->id), 'handle']);

    expect($permanent->refresh()->status)->toBe(EmailRecipientStatus::Failed)
        ->and($permanent->error)->toContain('422')
        ->and($temporary->refresh()->status)->toBe(EmailRecipientStatus::Queued)
        ->and($temporary->attempts)->toBe(1);

    // Esgotadas as tentativas (1 + 3 retries), vira falha.
    $temporary->forceFill(['attempts' => 3])->save();
    app()->call([new SendCampaignEmail($temporary->id), 'handle']);
    expect($temporary->refresh()->status)->toBe(EmailRecipientStatus::Failed);
});

test('limite diário de aquecimento segura o lote', function () {
    marketingSetup();
    config(['marketing.sending.daily_limit' => 3, 'marketing.sending.batch_size' => 10]);
    Queue::fake([SendCampaignEmail::class, DispatchCampaignBatch::class]);
    $campaign = EmailCampaign::factory()->sending()->create();
    EmailCampaignRecipient::factory()->count(5)->for($campaign, 'campaign')->create();

    app()->call([new DispatchCampaignBatch($campaign->id), 'handle']);
    app()->call([new DispatchCampaignBatch($campaign->id), 'handle']);

    Queue::assertPushed(SendCampaignEmail::class, 3);
    Queue::assertNotPushed(DispatchCampaignBatch::class);
    expect(EmailCampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'pending')->count())->toBe(2);
});

test('público vazio marca a campanha como falha', function () {
    marketingSetup();
    $campaign = readyCampaign(['status' => EmailCampaignStatus::Building]);

    app()->call([new BuildCampaignRecipients($campaign->id), 'handle']);

    expect($campaign->refresh()->status)->toBe(EmailCampaignStatus::Failed)
        ->and($campaign->last_error)->toContain('Nenhum destinatário');
});

test('agendada sai pelo comando quando chega a hora', function () {
    $fake = marketingSetup();
    marketingMember(user: ['email' => 'a@empresa.com']);
    $campaign = readyCampaign();
    app(CampaignService::class)->schedule($campaign, now()->addHour());

    $this->artisan('marketing:dispatch-scheduled')->assertSuccessful();
    expect($campaign->refresh()->status)->toBe(EmailCampaignStatus::Scheduled);

    $this->travel(2)->hours();
    $this->artisan('marketing:dispatch-scheduled')->assertSuccessful();

    expect($campaign->refresh()->status)->toBe(EmailCampaignStatus::Sent)
        ->and($fake->sentTo())->toBe(['a@empresa.com']);
});

test('editar campanha agendada volta para rascunho', function () {
    marketingSetup();
    marketingMember();
    $campaign = readyCampaign();
    $service = app(CampaignService::class);
    $service->schedule($campaign, now()->addDay());

    $service->saveDraft($campaign->refresh(), ['subject' => 'Assunto novo'], marketingSuperAdmin());

    expect($campaign->refresh()->status)->toBe(EmailCampaignStatus::Draft)
        ->and($campaign->test_sent_at)->toBeNull();
});

test('teste A/B: amostra dividida, restante espera e recebe a vencedora', function () {
    $fake = marketingSetup();
    foreach (range(1, 10) as $i) {
        marketingMember(user: ['email' => "p{$i}@empresa.com"]);
    }
    $campaign = readyCampaign(['subject' => 'Assunto A', 'ab_subject_b' => 'Assunto B', 'ab_sample_percent' => 40, 'ab_wait_hours' => 2]);

    app(CampaignService::class)->start($campaign);
    $campaign->refresh();

    expect($campaign->status)->toBe(EmailCampaignStatus::Sending)
        ->and($fake->sent)->toHaveCount(4)
        ->and(collect($fake->sent)->pluck('subject')->unique()->sort()->values()->all())->toBe(['Assunto A', 'Assunto B'])
        ->and(EmailCampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'held')->count())->toBe(6);

    // B abre mais.
    EmailCampaignRecipient::where('campaign_id', $campaign->id)->where('variant', 'b')->update(['first_opened_at' => now()]);

    $this->travel(3)->hours();
    $this->artisan('marketing:dispatch-scheduled')->assertSuccessful();

    $campaign->refresh();
    expect($campaign->ab_winner)->toBe('b')
        ->and($campaign->status)->toBe(EmailCampaignStatus::Sent)
        ->and($fake->sent)->toHaveCount(10)
        ->and(collect($fake->sent)->slice(4)->pluck('subject')->unique()->all())->toBe(['Assunto B']);
});

test('montagem respeita filtros salvos na campanha', function () {
    $fake = marketingSetup();
    marketingMember(['plan' => 'pro'], user: ['email' => 'pro@a.com']);
    marketingMember(['plan' => 'free'], user: ['email' => 'free@a.com']);
    $campaign = readyCampaign(['filters' => (new AudienceFilterData(plans: ['free']))->toArray()]);

    app(CampaignService::class)->start($campaign);

    expect($fake->sentTo())->toBe(['free@a.com']);
});

test('quem saiu pelo perfil depois de montado o público não recebe', function () {
    $fake = marketingSetup();
    $member = marketingMember(user: ['email' => 'saiu@a.com']);
    $campaign = EmailCampaign::factory()->sending()->create();
    $recipient = EmailCampaignRecipient::factory()->for($campaign, 'campaign')->status(EmailRecipientStatus::Queued)->create([
        'email' => 'saiu@a.com', 'user_id' => $member->id,
    ]);
    $member->forceFill(['marketing_opt_out_at' => now()])->save();

    app()->call([new SendCampaignEmail($recipient->id), 'handle']);

    expect($fake->sent)->toBeEmpty()
        ->and($recipient->refresh()->status)->toBe(EmailRecipientStatus::Suppressed);
});

test('erro de conta do provedor pausa a campanha sem queimar os destinatários', function () {
    $fake = marketingSetup();
    $campaign = EmailCampaign::factory()->sending()->create();
    $recipient = EmailCampaignRecipient::factory()->for($campaign, 'campaign')->status(EmailRecipientStatus::Queued)->create(['email' => 'a@a.com']);
    $fake->failures['a@a.com'] = EmailProviderException::account('Resend 403: domain not verified', 403);

    app()->call([new SendCampaignEmail($recipient->id), 'handle']);

    expect($campaign->refresh()->status)->toBe(EmailCampaignStatus::Paused)
        ->and($campaign->last_error)->toContain('domain not verified')
        ->and($recipient->refresh()->status)->toBe(EmailRecipientStatus::Pending)
        ->and($recipient->attempts)->toBe(0);
});

test('destinatário "queued" perdido há horas volta para a fila da campanha', function () {
    marketingSetup();
    Queue::fake([SendCampaignEmail::class]);
    $campaign = EmailCampaign::factory()->sending()->create();
    $lost = EmailCampaignRecipient::factory()->for($campaign, 'campaign')->status(EmailRecipientStatus::Queued)->create();
    EmailCampaignRecipient::whereKey($lost->id)->update(['queued_at' => now()->subHours(3), 'updated_at' => now()->subHours(3)]);

    app()->call([new DispatchCampaignBatch($campaign->id), 'handle']);

    // Voltou para pending e já saiu de novo no lote.
    Queue::assertPushed(SendCampaignEmail::class, fn ($job) => $job->recipientId === $lost->id);
    expect($lost->refresh()->status)->toBe(EmailRecipientStatus::Queued)
        ->and($lost->queued_at->isAfter(now()->subMinute()))->toBeTrue();
});
