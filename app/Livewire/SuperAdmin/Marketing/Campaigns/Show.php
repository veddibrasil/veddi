<?php

namespace App\Livewire\SuperAdmin\Marketing\Campaigns;

use App\Enums\EmailCampaignStatus;
use App\Enums\EmailRecipientStatus;
use App\Livewire\SuperAdmin\Concerns\RequiresSuperAdmin;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Marketing\CampaignService;
use App\Services\Marketing\CampaignStatsService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Relatório da campanha: funil, cliques por link, A/B e destinatários; ao vivo durante o envio. */
class Show extends Component
{
    use RequiresSuperAdmin;
    use WithPagination;

    #[Locked]
    public int $campaignId;

    #[Url(as: 'status')]
    public string $recipientStatus = '';

    #[Url(as: 'busca')]
    public string $search = '';

    public bool $confirmingCancel = false;

    public ?string $notice = null;

    public ?string $errorNotice = null;

    public function mount(EmailCampaign $campaign): void
    {
        $this->campaignId = $campaign->id;
        $this->notice = session('marketing_notice');
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return ['echo-private:superadmin.marketing,.MarketingCampaignUpdated' => 'onCampaignUpdated'];
    }

    public function onCampaignUpdated(array $payload = []): void
    {
        // Só re-renderiza (o render relê a campanha); outras campanhas são ignoradas.
        if (($payload['campaign_id'] ?? null) !== $this->campaignId) {
            $this->skipRender();
        }
    }

    public function updatingRecipientStatus(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function pause(CampaignService $campaigns): void
    {
        $this->notice = $campaigns->pause($this->campaign()) ? 'Campanha pausada.' : null;
    }

    public function resume(CampaignService $campaigns): void
    {
        $this->notice = $campaigns->resume($this->campaign()) ? 'Envio retomado.' : null;
    }

    public function cancel(CampaignService $campaigns): void
    {
        $this->confirmingCancel = false;
        $this->notice = $campaigns->cancel($this->campaign()) ? 'Campanha cancelada. Nada mais será enviado.' : null;
    }

    public function refreshStats(CampaignStatsService $stats): void
    {
        $stats->refresh($this->campaign());
        $this->notice = 'Números atualizados.';
    }

    public function duplicate(CampaignService $campaigns): void
    {
        $copy = $campaigns->duplicate($this->campaign(), auth()->user());

        $this->redirectRoute('superadmin.marketing.campaigns.edit', $copy, navigate: true);
    }

    public function render(CampaignStatsService $stats)
    {
        $campaign = $this->campaign();

        $recipients = EmailCampaignRecipient::where('campaign_id', $campaign->id)
            ->with('company:id,name')
            ->when($this->recipientStatus !== '', fn ($query) => $query->where('status', $this->recipientStatus))
            ->when($this->search !== '', fn ($query) => $query->where('email', 'like', '%'.mb_strtolower($this->search).'%'))
            ->orderBy('id')
            ->paginate(25);

        $statusCounts = EmailCampaignRecipient::where('campaign_id', $campaign->id)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as total')
            ->pluck('total', 'status');

        $processed = $campaign->recipients_count - (int) $statusCounts->only(EmailRecipientStatus::open())->sum();

        return view('livewire.super-admin.marketing.campaigns.show', [
            'campaign' => $campaign,
            'recipients' => $recipients,
            'statusCounts' => $statusCounts,
            'progress' => $campaign->recipients_count > 0 ? (int) floor(max(0, $processed) / $campaign->recipients_count * 100) : 0,
            'funnel' => $stats->funnel($campaign),
            'links' => $stats->clicksByLink($campaign),
            'ab' => $campaign->hasAbTest() ? $stats->abResults($campaign) : null,
            'live' => in_array($campaign->status, [EmailCampaignStatus::Building, EmailCampaignStatus::Sending], true),
            'recipientStatuses' => EmailRecipientStatus::cases(),
        ])->layout('layouts.app', ['title' => 'Super Admin — '.$campaign->name]);
    }

    private function campaign(): EmailCampaign
    {
        return EmailCampaign::with(['segment:id,name', 'creator:id,name', 'automationStep.automation:id,name'])->findOrFail($this->campaignId);
    }
}
