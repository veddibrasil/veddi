<?php

namespace App\Livewire\SuperAdmin\Marketing\Campaigns;

use App\Enums\EmailCampaignStatus;
use App\Livewire\SuperAdmin\Concerns\RequiresSuperAdmin;
use App\Models\EmailAutomation;
use App\Models\EmailCampaign;
use App\Models\EmailSegment;
use App\Models\EmailTemplate;
use App\Services\Marketing\CampaignService;
use App\Services\Marketing\DefaultEmailAutomations;
use App\Services\Marketing\DefaultEmailCampaigns;
use App\Services\Marketing\DefaultEmailSegments;
use App\Services\Marketing\DefaultEmailTemplates;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use RequiresSuperAdmin;
    use WithPagination;

    #[Url(as: 'busca')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $status = '';

    public ?int $cancellingId = null;

    public bool $confirmingCancel = false;

    public bool $choosingPreset = false;

    public ?string $notice = null;

    public ?string $errorNotice = null;

    public function mount(): void
    {
        $this->notice = session('marketing_notice');
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return ['echo-private:superadmin.marketing,.MarketingCampaignUpdated' => '$refresh'];
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    /** Rascunho a partir de uma campanha pronta (template + segmento padrão, instalados se faltarem). */
    public function createFromPreset(string $key, DefaultEmailCampaigns $presets): void
    {
        abort_unless(array_key_exists($key, $presets->definitions()), 404);

        $campaign = $presets->createDraft($key, auth()->user());

        session()->flash('marketing_notice', 'Rascunho criado a partir do modelo. Revise o conteúdo, confira o público e envie um teste.');
        $this->redirectRoute('superadmin.marketing.campaigns.edit', $campaign, navigate: true);
    }

    /** Instala de uma vez templates, segmentos e automações (pausadas) prontos. */
    public function installStarterKit(DefaultEmailTemplates $templates, DefaultEmailSegments $segments, DefaultEmailAutomations $automations): void
    {
        $created = [
            'templates' => $templates->install(auth()->id()),
            'segmentos' => $segments->install(auth()->id()),
            'automações (pausadas)' => $automations->install(auth()->user()),
        ];

        $parts = collect($created)->filter()->map(fn (int $count, string $label) => "{$count} {$label}");

        $this->notice = $parts->isEmpty()
            ? 'Os modelos prontos já estão instalados.'
            : 'Modelos prontos instalados: '.$parts->implode(', ').'.';
    }

    public function duplicate(int $campaignId, CampaignService $campaigns): void
    {
        $copy = $campaigns->duplicate($this->find($campaignId), auth()->user());

        $this->redirectRoute('superadmin.marketing.campaigns.edit', $copy, navigate: true);
    }

    public function pause(int $campaignId, CampaignService $campaigns): void
    {
        $this->notice = $campaigns->pause($this->find($campaignId)) ? 'Campanha pausada.' : null;
    }

    public function resume(int $campaignId, CampaignService $campaigns): void
    {
        $this->notice = $campaigns->resume($this->find($campaignId)) ? 'Envio retomado.' : null;
    }

    public function unschedule(int $campaignId, CampaignService $campaigns): void
    {
        $this->notice = $campaigns->unschedule($this->find($campaignId)) ? 'Agendamento removido; a campanha voltou a rascunho.' : null;
    }

    public function confirmCancel(int $campaignId): void
    {
        $this->cancellingId = $campaignId;
        $this->confirmingCancel = true;
    }

    public function cancel(CampaignService $campaigns): void
    {
        $this->confirmingCancel = false;

        if ($this->cancellingId && $campaigns->cancel($this->find($this->cancellingId))) {
            $this->notice = 'Campanha cancelada. Nada mais será enviado.';
        }

        $this->cancellingId = null;
    }

    public function delete(int $campaignId): void
    {
        $campaign = $this->find($campaignId);

        if ($campaign->status !== EmailCampaignStatus::Draft) {
            $this->errorNotice = 'Só rascunhos podem ser excluídos.';

            return;
        }

        $campaign->delete();
        $this->notice = 'Rascunho excluído.';
    }

    public function render()
    {
        $campaigns = EmailCampaign::campaigns()
            ->with('segment:id,name')
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('subject', 'like', '%'.$this->search.'%')))
            ->latest('updated_at')
            ->paginate(20);

        $recentlyFinished = EmailCampaign::campaigns()
            ->whereIn('status', [EmailCampaignStatus::Sent->value, EmailCampaignStatus::Failed->value])
            ->where('finished_at', '>=', now()->subDay())
            ->latest('finished_at')
            ->limit(3)
            ->get(['id', 'name', 'status', 'sent_count', 'failed_count', 'last_error', 'finished_at']);

        return view('livewire.super-admin.marketing.campaigns.index', [
            'campaigns' => $campaigns,
            'recentlyFinished' => $recentlyFinished,
            'statuses' => EmailCampaignStatus::options(),
            'presets' => app(DefaultEmailCampaigns::class)->definitions(),
            'needsStarterKit' => ! EmailTemplate::exists() || ! EmailSegment::exists() || ! EmailAutomation::exists(),
        ])->layout('layouts.app', ['title' => 'Super Admin — E-mail marketing']);
    }

    private function find(int $campaignId): EmailCampaign
    {
        return EmailCampaign::campaigns()->findOrFail($campaignId);
    }
}
