<?php

use App\Enums\EmailRecipientStatus;
use App\Enums\EmailSuppressionReason;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailSuppression;
use App\Services\Marketing\CampaignService;
use App\Services\Marketing\TrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => marketingSetup());

function unsubscribeTarget(): array
{
    $member = marketingMember(user: ['email' => 'Dono@Empresa.com']);
    $campaign = EmailCampaign::factory()->sending()->create();
    $recipient = EmailCampaignRecipient::factory()->for($campaign, 'campaign')->status(EmailRecipientStatus::Sent)->create([
        'email' => 'dono@empresa.com', 'user_id' => $member->id,
    ]);

    return [$member, $recipient, app(TrackingService::class)->unsubscribeUrl($recipient)];
}

test('link sem assinatura ou adulterado falha', function () {
    [, $recipient, $url] = unsubscribeTarget();

    $this->get(route('marketing.unsubscribe', ['recipient' => $recipient->id]))->assertForbidden();
    $this->post(route('marketing.unsubscribe.store', ['recipient' => $recipient->id]))->assertForbidden();

    $other = EmailCampaignRecipient::factory()->for($recipient->campaign, 'campaign')->create();
    $tampered = str_replace('/email/u/'.$recipient->id, '/email/u/'.$other->id, $url);
    $this->post($tampered)->assertForbidden();

    expect(EmailSuppression::count())->toBe(0);
});

test('POST de um clique funciona sem login e sem CSRF', function () {
    [$member, $recipient, $url] = unsubscribeTarget();

    $this->call('POST', $url, ['List-Unsubscribe' => 'One-Click'])->assertOk();
    $this->call('POST', $url, ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect(EmailSuppression::where('email', 'dono@empresa.com')->value('reason'))->toBe(EmailSuppressionReason::Unsubscribed)
        ->and($recipient->refresh()->unsubscribed_at)->not->toBeNull()
        ->and($recipient->campaign->refresh()->unsubscribed_count)->toBe(1)
        ->and($member->refresh()->marketing_opt_out_at)->not->toBeNull();
});

test('página de preferências descadastra e permite voltar', function () {
    [$member, , $url] = unsubscribeTarget();

    $this->get($url)->assertOk()->assertSee('Não quero mais receber')->assertSee('d***@empresa.com')->assertDontSee('dono@empresa.com');

    $this->post($url, ['action' => 'unsubscribe'])->assertRedirect();
    $this->get($url)->assertSee('Quero voltar a receber');

    $this->post($url, ['action' => 'resubscribe'])->assertRedirect();
    expect(EmailSuppression::count())->toBe(0)
        ->and($member->refresh()->wantsMarketingEmails())->toBeTrue();
});

test('voltar a receber não desfaz bounce nem spam', function () {
    [, , $url] = unsubscribeTarget();
    EmailSuppression::create(['email' => 'dono@empresa.com', 'reason' => EmailSuppressionReason::Complaint]);

    $this->post($url, ['action' => 'resubscribe'])->assertRedirect();

    expect(EmailSuppression::where('email', 'dono@empresa.com')->exists())->toBeTrue();
});

test('e-mail descadastrado não entra na próxima campanha', function () {
    $fake = marketingSetup();
    [, , $url] = unsubscribeTarget();
    marketingMember(user: ['email' => 'outro@empresa.com']);

    $this->call('POST', $url)->assertOk();

    $next = EmailCampaign::factory()->create(['test_sent_at' => now()]);
    app(CampaignService::class)->start($next);

    expect($fake->sentTo())->toBe(['outro@empresa.com']);
});
