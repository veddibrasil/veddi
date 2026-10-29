<?php

namespace App\Jobs;

use App\Enums\EmailCampaignStatus;
use App\Enums\EmailRecipientStatus;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Marketing\CampaignService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Pega o próximo lote de destinatários "pending", marca "queued" e enfileira um envio para cada.
 * Confere o status antes: pausada ou cancelada encerra. Reagenda a si mesmo enquanto houver
 * lote cheio; o agendador (marketing:dispatch-scheduled) cobre lote perdido e o limite diário.
 */
class DispatchCampaignBatch implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $campaignId)
    {
        $this->onQueue(config('marketing.queue'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->campaignId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("marketing-batch-{$this->campaignId}"))->dontRelease()->expireAfter(300)];
    }

    public function handle(CampaignService $campaigns): void
    {
        $campaign = EmailCampaign::find($this->campaignId);

        if (! $campaign || $campaign->status !== EmailCampaignStatus::Sending) {
            return;
        }

        $this->failStaleSends($campaign);

        $batchSize = max(1, (int) config('marketing.sending.batch_size', 500));
        $remainingToday = $this->remainingToday();
        $limit = $remainingToday === null ? $batchSize : min($batchSize, $remainingToday);

        if ($limit <= 0) {
            Log::channel('marketing')->info('Marketing: limite diário de envio atingido', ['campaign_id' => $campaign->id]);

            return;
        }

        $ids = EmailCampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', EmailRecipientStatus::Pending->value)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty()) {
            $campaigns->finishIfDone($campaign);

            return;
        }

        $queued = EmailCampaignRecipient::whereIn('id', $ids->all())
            ->where('status', EmailRecipientStatus::Pending->value)
            ->update(['status' => EmailRecipientStatus::Queued->value, 'queued_at' => now()]);

        foreach ($ids as $id) {
            SendCampaignEmail::dispatch($id);
        }

        $morePending = EmailCampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', EmailRecipientStatus::Pending->value)
            ->exists();
        $roomToday = $remainingToday === null || $remainingToday > $limit;

        if ($queued === $limit && $morePending && $roomToday) {
            $ratePerSecond = max(1, (int) config('marketing.sending.rate_per_second', 10));

            self::dispatch($campaign->id)->delay(now()->addSeconds((int) ceil($limit / $ratePerSecond)));
        }
    }

    /** Aquecimento do domínio: envios por dia somando campanhas e automações (null = sem limite). */
    private function remainingToday(): ?int
    {
        $dailyLimit = (int) config('marketing.sending.daily_limit', 0);

        if ($dailyLimit <= 0) {
            return null;
        }

        $startOfDay = now('America/Sao_Paulo')->startOfDay()->setTimezone(config('app.timezone'));

        return max(0, $dailyLimit - EmailCampaignRecipient::where('queued_at', '>=', $startOfDay)->count());
    }

    /**
     * Worker morreu no meio do envio: não dá para saber se o provedor aceitou. Marca falha em vez
     * de reenviar — ninguém recebe a mesma campanha duas vezes.
     */
    private function failStaleSends(EmailCampaign $campaign): void
    {
        $stale = EmailCampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', EmailRecipientStatus::Sending->value)
            ->where('updated_at', '<', now()->subMinutes((int) config('marketing.sending.stale_after_minutes', 15)))
            ->update([
                'status' => EmailRecipientStatus::Failed->value,
                'error' => 'Envio interrompido no meio; não reenviado para evitar duplicidade.',
            ]);

        if ($stale > 0) {
            EmailCampaign::whereKey($campaign->id)->increment('failed_count', $stale);
        }

        // Job de envio perdido (Redis limpo, deploy): "queued" há horas volta para a fila da
        // campanha. Sem risco de duplicar: só sai quem ganhar a reserva queued → sending.
        EmailCampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', EmailRecipientStatus::Queued->value)
            ->where('queued_at', '<', now()->subHours(2))
            ->where('updated_at', '<', now()->subHour())
            ->update(['status' => EmailRecipientStatus::Pending->value]);
    }
}
