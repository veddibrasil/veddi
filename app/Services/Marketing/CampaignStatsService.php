<?php

namespace App\Services\Marketing;

use App\Enums\EmailCampaignStatus;
use App\Enums\EmailEventType;
use App\Enums\EmailRecipientStatus;
use App\Events\MarketingCampaignUpdated;
use App\Models\EmailCampaign;
use App\Models\EmailEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Números da campanha. Os contadores de email_campaigns são recalculados a partir dos
 * destinatários (fonte de verdade); os incrementos durante o envio são só para o progresso ao vivo.
 */
class CampaignStatsService
{
    public function refresh(EmailCampaign $campaign): EmailCampaign
    {
        $row = DB::table('email_campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->selectRaw('COUNT(*) as recipients_count')
            ->selectRaw('COUNT(sent_at) as sent_count')
            ->selectRaw('COUNT(delivered_at) as delivered_count')
            ->selectRaw('COUNT(first_opened_at) as opened_count')
            ->selectRaw('COUNT(first_clicked_at) as clicked_count')
            ->selectRaw('COUNT(bounced_at) as bounced_count')
            ->selectRaw('COUNT(complained_at) as complained_count')
            ->selectRaw('COUNT(unsubscribed_at) as unsubscribed_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed_count', [EmailRecipientStatus::Failed->value])
            ->first();

        $campaign->forceFill(array_map('intval', (array) $row))->save();

        $this->broadcast($campaign);

        return $campaign;
    }

    public function broadcast(EmailCampaign $campaign): void
    {
        rescue(fn () => MarketingCampaignUpdated::dispatch(
            $campaign->id,
            $campaign->status->value,
            (int) $campaign->sent_count,
            (int) $campaign->recipients_count,
        ), report: false);
    }

    /** @return list<array{label: string, value: int, percent: float}> */
    public function funnel(EmailCampaign $campaign): array
    {
        $sent = max((int) $campaign->sent_count, 0);
        $step = fn (string $label, int $value) => [
            'label' => $label,
            'value' => $value,
            'percent' => $sent > 0 ? round($value / $sent * 100, 1) : 0.0,
        ];

        return [
            $step('Enviados', $sent),
            $step('Entregues', (int) $campaign->delivered_count),
            $step('Abertos', (int) $campaign->opened_count),
            $step('Clicados', (int) $campaign->clicked_count),
        ];
    }

    /** @return Collection<int, object{url: string, clicks: int, unique_clicks: int}> */
    public function clicksByLink(EmailCampaign $campaign): Collection
    {
        return EmailEvent::query()
            ->where('campaign_id', $campaign->id)
            ->where('type', EmailEventType::Click->value)
            ->groupBy('url')
            ->selectRaw('url, COUNT(*) as clicks, COUNT(DISTINCT recipient_id) as unique_clicks')
            ->orderByDesc('unique_clicks')
            ->limit(50)
            ->toBase()
            ->get();
    }

    /** @return array<string, array{subject: string, sent: int, opened: int, clicked: int, open_rate: float}> */
    public function abResults(EmailCampaign $campaign): array
    {
        $rows = DB::table('email_campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->whereIn('variant', ['a', 'b'])
            ->whereNotNull('sent_at')
            // Só a amostra: depois da decisão a vencedora vai ao restante e distorceria a comparação.
            ->when($campaign->ab_decided_at, fn ($query) => $query->where('sent_at', '<', $campaign->ab_decided_at))
            ->groupBy('variant')
            ->selectRaw('variant, COUNT(*) as sent, COUNT(first_opened_at) as opened, COUNT(first_clicked_at) as clicked')
            ->get()
            ->keyBy('variant');

        $results = [];

        foreach (['a', 'b'] as $variant) {
            $sent = (int) ($rows[$variant]->sent ?? 0);
            $opened = (int) ($rows[$variant]->opened ?? 0);

            $results[$variant] = [
                'subject' => $campaign->subjectForVariant($variant),
                'sent' => $sent,
                'opened' => $opened,
                'clicked' => (int) ($rows[$variant]->clicked ?? 0),
                'open_rate' => $sent > 0 ? round($opened / $sent * 100, 1) : 0.0,
            ];
        }

        return $results;
    }

    /**
     * Bounce acima de 5% ou spam acima de 0,1% (com amostra mínima): pausa a campanha e avisa
     * no Discord. Reputação do domínio vale mais que terminar o disparo.
     */
    public function checkHealth(EmailCampaign $campaign): bool
    {
        $sent = (int) $campaign->sent_count;

        if ($campaign->status !== EmailCampaignStatus::Sending || $sent < (int) config('marketing.health.min_sample', 50)) {
            return true;
        }

        $bounceRate = $campaign->bounced_count / $sent;
        $complaintRate = $campaign->complained_count / $sent;
        $tooManyBounces = $bounceRate > (float) config('marketing.health.max_bounce_rate');
        $tooManyComplaints = $complaintRate > (float) config('marketing.health.max_complaint_rate');

        if (! $tooManyBounces && ! $tooManyComplaints) {
            return true;
        }

        $reason = $tooManyComplaints
            ? sprintf('Pausada automaticamente: reclamações de spam em %.2f%% dos envios.', $complaintRate * 100)
            : sprintf('Pausada automaticamente: bounce em %.1f%% dos envios.', $bounceRate * 100);

        $paused = EmailCampaign::whereKey($campaign->id)
            ->where('status', EmailCampaignStatus::Sending->value)
            ->update(['status' => EmailCampaignStatus::Paused->value, 'last_error' => $reason]);

        if ($paused === 1) {
            Log::channel('discord')->warning('E-mail marketing: campanha pausada por entregabilidade', [
                'campaign_id' => $campaign->id,
                'sent' => $sent,
                'bounce_rate' => round($bounceRate * 100, 2),
                'complaint_rate' => round($complaintRate * 100, 3),
            ]);

            $this->broadcast($campaign->refresh());
        }

        return false;
    }
}
