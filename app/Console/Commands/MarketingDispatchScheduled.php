<?php

namespace App\Console\Commands;

use App\Enums\EmailCampaignStatus;
use App\Jobs\DispatchCampaignBatch;
use App\Models\EmailCampaign;
use App\Services\Marketing\CampaignService;
use Illuminate\Console\Command;

class MarketingDispatchScheduled extends Command
{
    protected $signature = 'marketing:dispatch-scheduled';

    protected $description = 'Inicia campanhas agendadas, decide testes A/B vencidos e retoma lotes de campanhas em envio.';

    public function handle(CampaignService $campaigns): int
    {
        $started = 0;

        EmailCampaign::campaigns()
            ->where('status', EmailCampaignStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->each(function (EmailCampaign $campaign) use ($campaigns, &$started) {
                $started += (int) $campaigns->start($campaign, fromScheduler: true);
            });

        // Cobre lote que se perdeu (worker reiniciado) e o limite diário do aquecimento: o
        // DispatchCampaignBatch é único por campanha, então rodar a cada minuto não acumula.
        EmailCampaign::where('status', EmailCampaignStatus::Sending->value)
            ->each(function (EmailCampaign $campaign) use ($campaigns) {
                $campaigns->decideAbTest($campaign);
                DispatchCampaignBatch::dispatch($campaign->id);
            });

        if ($started > 0) {
            $this->info("{$started} campanha(s) agendada(s) iniciada(s).");
        }

        return self::SUCCESS;
    }
}
