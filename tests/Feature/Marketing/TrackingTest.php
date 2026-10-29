<?php

use App\Enums\EmailRecipientStatus;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailEvent;
use App\Services\Marketing\TrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => marketingSetup());

function trackedRecipient(): EmailCampaignRecipient
{
    $campaign = EmailCampaign::factory()->sending()->create();

    return EmailCampaignRecipient::factory()->for($campaign, 'campaign')->status(EmailRecipientStatus::Sent)->create();
}

test('clique válido registra e redireciona para o link da campanha', function () {
    $recipient = trackedRecipient();
    $url = app(TrackingService::class)->clickUrl($recipient, 'https://veddi.com.br/novidades');

    $this->get($url)->assertRedirect('https://veddi.com.br/novidades');
    $this->get($url)->assertRedirect('https://veddi.com.br/novidades');

    expect(EmailEvent::where('type', 'click')->count())->toBe(2)
        ->and($recipient->refresh()->first_clicked_at)->not->toBeNull()
        ->and($recipient->first_opened_at)->not->toBeNull()
        ->and($recipient->campaign->refresh()->clicked_count)->toBe(1)
        ->and(EmailEvent::first()->ip_hash)->not->toBe('127.0.0.1');
});

test('redirect recusa URL que não está na campanha, mesmo assinada', function () {
    $recipient = trackedRecipient();
    $url = app(TrackingService::class)->clickUrl($recipient, 'https://phishing.example/login');

    $this->get($url)->assertNotFound();
    expect(EmailEvent::count())->toBe(0);
});

test('redirect sem assinatura ou com destino trocado falha', function () {
    $recipient = trackedRecipient();
    $url = app(TrackingService::class)->clickUrl($recipient, 'https://veddi.com.br/novidades');
    $swapped = preg_replace('/u=[^&]+/', 'u='.TrackingService::encodeUrl('https://phishing.example'), $url);

    $this->get(route('marketing.click', ['recipient' => $recipient->id, 'u' => TrackingService::encodeUrl('https://veddi.com.br/novidades')]))->assertNotFound();
    $this->get($swapped)->assertNotFound();
});

test('pixel de abertura registra a primeira abertura e sempre devolve a imagem', function () {
    $recipient = trackedRecipient();
    $url = app(TrackingService::class)->openPixelUrl($recipient);

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/gif');
    $this->get($url)->assertOk();
    $this->get(route('marketing.open', ['recipient' => $recipient->id]))->assertOk();

    expect(EmailEvent::where('type', 'open')->count())->toBe(2)
        ->and($recipient->campaign->refresh()->opened_count)->toBe(1);
});
