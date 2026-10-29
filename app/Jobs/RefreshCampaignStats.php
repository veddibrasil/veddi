<?php

namespace App\Jobs;

use App\Models\EmailCampaign;
use App\Services\Marketing\CampaignStatsService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Recalcula os contadores da campanha e confere a saúde (pausa por bounce/spam alto). */
class RefreshCampaignStats implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    // Rajada de webhooks vira um recálculo por vez; o lock sai quando o job começa, então o que
    // chegar durante o recálculo enfileira o próximo em vez de ser descartado.
    public int $uniqueFor = 30;

    public function __construct(public readonly int $campaignId)
    {
        $this->onQueue(config('marketing.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->campaignId;
    }

    public function handle(CampaignStatsService $stats): void
    {
        $campaign = EmailCampaign::find($this->campaignId);

        if (! $campaign) {
            return;
        }

        $stats->checkHealth($stats->refresh($campaign));
    }
}
