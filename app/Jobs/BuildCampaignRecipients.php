<?php

namespace App\Jobs;

use App\Enums\EmailCampaignStatus;
use App\Models\EmailCampaign;
use App\Services\Marketing\AudienceResolver;
use App\Services\Marketing\CampaignService;
use App\Services\Marketing\TemplateRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Monta o público da campanha em blocos: normaliza e-mail, tira supressão/opt-out e grava com
 * insertOrIgnore (unique campaign_id+email). Congela o HTML. Rodar de novo não duplica ninguém.
 */
class BuildCampaignRecipients implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    // Abaixo do retry_after da fila (90s): passar disso faria o Redis entregar o job de novo.
    // Morreu no meio? O retry refaz rápido (insertOrIgnore pula quem já entrou).
    public int $timeout = 85;

    public function __construct(public readonly int $campaignId)
    {
        $this->onQueue(config('marketing.queue'));
        $this->afterCommit();
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("marketing-build-{$this->campaignId}"))->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(CampaignService $campaigns, AudienceResolver $audience, TemplateRenderer $renderer): void
    {
        $campaign = EmailCampaign::with('segment')->find($this->campaignId);

        if (! $campaign || $campaign->status !== EmailCampaignStatus::Building) {
            return;
        }

        if (blank($campaign->html_snapshot)) {
            $campaign->forceFill(['html_snapshot' => $renderer->snapshot($campaign)])->save();
        }

        $interrupted = false;

        $audience->chunk($campaign->audienceFilter(), (int) config('marketing.sending.build_chunk', 1000), function (Collection $rows) use ($campaign, $campaigns, &$interrupted) {
            // Pausa/cancelamento no meio da montagem: para no próximo bloco.
            if (EmailCampaign::whereKey($campaign->id)->toBase()->value('status') !== EmailCampaignStatus::Building->value) {
                $interrupted = true;

                return false;
            }

            $campaigns->addRecipients($campaign, $rows);
        });

        if ($interrupted) {
            return;
        }

        $campaigns->assignAbVariants($campaign);

        $total = $campaign->recipients()->count();

        if ($total === 0) {
            $campaigns->transition($campaign, [EmailCampaignStatus::Building], EmailCampaignStatus::Failed, [
                'last_error' => 'Nenhum destinatário depois de descontar supressões e opt-out.',
                'finished_at' => now(),
            ]);

            return;
        }

        if (! $campaigns->transition($campaign, [EmailCampaignStatus::Building], EmailCampaignStatus::Sending, [
            'started_at' => now(),
            'recipients_count' => $total,
        ])) {
            return;
        }

        Log::channel('marketing')->info('Marketing: público montado', [
            'campaign_id' => $campaign->id,
            'recipients' => $total,
        ]);

        DispatchCampaignBatch::dispatch($campaign->id);
    }
}
