<?php

namespace App\Livewire\SuperAdmin\Marketing\Campaigns;

use App\DTOs\AudienceFilterData;
use App\Exceptions\EmailProviderException;
use App\Livewire\SuperAdmin\Concerns\RequiresSuperAdmin;
use App\Livewire\SuperAdmin\Marketing\Concerns\EditsAudienceFilters;
use App\Livewire\SuperAdmin\Marketing\Concerns\EditsEmailBlocks;
use App\Models\Company;
use App\Models\EmailCampaign;
use App\Models\EmailContactList;
use App\Models\EmailSegment;
use App\Models\EmailTemplate;
use App\Services\Marketing\AudienceResolver;
use App\Services\Marketing\CampaignService;
use App\Services\Marketing\TemplateRenderer;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use LogicException;

/**
 * Criação/edição de campanha em quatro passos: conteúdo, público, revisão (preview + teste +
 * checklist) e envio. Cada passo salva o rascunho no servidor; o disparo recalcula o público.
 */
class Wizard extends Component
{
    use EditsAudienceFilters;
    use EditsEmailBlocks;
    use RequiresSuperAdmin;

    public const STEPS = [1 => 'Conteúdo', 2 => 'Público', 3 => 'Revisão', 4 => 'Envio'];

    #[Locked]
    public ?int $campaignId = null;

    public int $step = 1;

    public string $name = '';

    public string $subject = '';

    public string $preheader = '';

    public string $fromName = '';

    public string $fromEmail = '';

    public string $replyTo = '';

    public ?int $templateId = null;

    public string $audienceMode = 'filters';

    public ?int $segmentId = null;

    public array $contactListIds = [];

    public bool $includeOwners = false;

    public bool $abEnabled = false;

    public string $abSubjectB = '';

    public int $abSamplePercent = 20;

    public int $abWaitHours = 4;

    public string $testEmails = '';

    public string $sendMode = 'now';

    public string $scheduledAt = '';

    public bool $confirmingDispatch = false;

    public ?string $notice = null;

    public ?string $errorNotice = null;

    public function mount(?EmailCampaign $campaign = null): void
    {
        $this->notice = session('marketing_notice');

        if ($campaign?->exists) {
            abort_if($campaign->isAutomation(), 404);

            if (! $campaign->status->isEditable()) {
                $this->redirectRoute('superadmin.marketing.campaigns.show', $campaign, navigate: true);

                return;
            }

            $this->fillFrom($campaign);

            return;
        }

        $this->fromName = (string) config('marketing.from.name');
        $this->fromEmail = (string) config('marketing.from.address');
        $this->replyTo = (string) config('marketing.reply_to');
        $this->blocks = [
            TemplateRenderer::newBlock('header'),
            TemplateRenderer::newBlock('text'),
            TemplateRenderer::newBlock('button'),
        ];
        $this->resetFilters();

        if ($listId = request()->integer('lista')) {
            $list = EmailContactList::findOrFail($listId);
            $this->audienceMode = 'contacts';
            $this->contactListIds = [$list->id];
        }
    }

    public function loadTemplate(): void
    {
        $template = $this->templateId ? EmailTemplate::find($this->templateId) : null;

        if (! $template) {
            return;
        }

        $this->blocks = array_map(
            fn (array $block) => ['key' => \Illuminate\Support\Str::random(8)] + $block,
            $template->blocks ?? [],
        );
        $this->subject = $this->subject !== '' ? $this->subject : (string) $template->subject_default;
        $this->preheader = $this->preheader !== '' ? $this->preheader : (string) $template->preheader_default;
        $this->notice = "Template “{$template->name}” carregado.";
    }

    public function goToStep(int $target): void
    {
        $target = max(1, min(4, $target));

        // Voltar não valida; avançar salva o passo atual (e exige o rascunho criado).
        if ($target > $this->step) {
            if (! $this->saveStep($this->step)) {
                return;
            }

            if ($target > $this->step + 1 && ! $this->campaignId) {
                return;
            }
        }

        $this->step = $target;
        $this->resetErrorBag();
    }

    public function next(): void
    {
        $this->goToStep($this->step + 1);
    }

    public function back(): void
    {
        $this->goToStep($this->step - 1);
    }

    public function save(): void
    {
        if ($this->saveStep($this->step)) {
            $this->notice = 'Rascunho salvo.';
        }
    }

    public function sendTest(CampaignService $campaigns): void
    {
        $this->errorNotice = null;

        if (! $campaign = $this->campaign()) {
            return;
        }

        $emails = preg_split('/[\s,;]+/', trim($this->testEmails), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        try {
            $sent = $campaigns->sendTest($campaign, $emails, $this->previewCompanyId);
        } catch (InvalidArgumentException $exception) {
            $this->addError('testEmails', $exception->getMessage());

            return;
        } catch (EmailProviderException $exception) {
            $this->errorNotice = 'O provedor recusou o envio de teste: '.$exception->getMessage();

            return;
        }

        unset($this->checklist);
        $this->notice = $sent === 1 ? 'Teste enviado.' : "Teste enviado para {$sent} e-mails.";
    }

    public function confirmDispatch(): void
    {
        $this->errorNotice = null;

        if (! $campaign = $this->campaign()) {
            return;
        }

        if ($this->sendMode === 'schedule' && ! $this->parsedSchedule()) {
            $this->addError('scheduledAt', 'Escolha uma data e hora no futuro.');

            return;
        }

        if (! app(CampaignService::class)->canDispatch($campaign)) {
            $this->errorNotice = 'Complete o checklist da revisão antes de disparar.';
            $this->step = 3;

            return;
        }

        $this->confirmingDispatch = true;
    }

    public function dispatchCampaign(CampaignService $campaigns): void
    {
        $this->confirmingDispatch = false;

        if (! $campaign = $this->campaign()) {
            return;
        }

        try {
            if ($this->sendMode === 'schedule') {
                $at = $this->parsedSchedule();

                if (! $at) {
                    $this->addError('scheduledAt', 'Escolha uma data e hora no futuro.');

                    return;
                }

                $campaigns->schedule($campaign, $at);
                session()->flash('marketing_notice', 'Campanha agendada para '.$at->timezone('America/Sao_Paulo')->format('d/m/Y \à\s H:i').'.');
            } else {
                if (! $campaigns->start($campaign)) {
                    $this->errorNotice = 'A campanha já está sendo disparada.';

                    return;
                }

                session()->flash('marketing_notice', 'Disparo iniciado. O público está sendo montado.');
            }
        } catch (LogicException|InvalidArgumentException $exception) {
            $this->errorNotice = $exception->getMessage();

            return;
        }

        $this->redirectRoute('superadmin.marketing.campaigns.show', $campaign, navigate: true);
    }

    #[Computed]
    public function audienceCount(): int
    {
        $filters = $this->currentAudience();

        return $filters ? app(AudienceResolver::class)->count($filters) : 0;
    }

    /** @return list<array{key: string, label: string, ok: bool, hint: ?string}> */
    #[Computed]
    public function checklist(): array
    {
        $campaign = $this->campaign();

        return $campaign ? app(CampaignService::class)->checklist($campaign) : [];
    }

    #[Computed]
    public function audienceSummary(): array
    {
        return $this->currentAudience()?->describe() ?? [];
    }

    #[Computed]
    public function previewCompanies()
    {
        return Company::withoutGlobalScopes()->where('active', true)->orderBy('name')->limit(200)->get(['id', 'name']);
    }

    protected function previewPreheader(): ?string
    {
        return $this->preheader;
    }

    public function render()
    {
        return view('livewire.super-admin.marketing.campaigns.wizard', [
            'templates' => EmailTemplate::orderBy('name')->get(['id', 'name']),
            'segments' => EmailSegment::orderBy('name')->get(['id', 'name', 'last_count']),
            'contactLists' => EmailContactList::orderBy('name')->withCount('contacts')->get(),
            'campaign' => $this->campaign(),
        ])->layout('layouts.app', ['title' => 'Super Admin — Campanha de e-mail']);
    }

    private function saveStep(int $step): bool
    {
        $this->notice = null;
        $this->errorNotice = null;

        return match ($step) {
            1 => $this->saveContent(),
            2 => $this->saveAudience(),
            default => $this->campaignId !== null,
        };
    }

    private function saveContent(): bool
    {
        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'subject' => ['required', 'string', 'max:200'],
            'preheader' => ['nullable', 'string', 'max:200'],
            'fromName' => ['required', 'string', 'max:100'],
            'fromEmail' => ['required', 'email', 'max:150'],
            'replyTo' => ['nullable', 'email', 'max:150'],
            ...$this->blockRules(),
        ], attributes: [
            'name' => 'nome interno', 'subject' => 'assunto', 'preheader' => 'pré-cabeçalho',
            'fromName' => 'nome do remetente', 'fromEmail' => 'e-mail do remetente', 'replyTo' => 'responder para',
        ]);

        if ($unknown = $this->unknownBlockVariables($this->subject, $this->preheader)) {
            $this->addError('blocks', 'Variáveis desconhecidas: '.implode(', ', array_map(fn ($name) => '{{'.$name.'}}', $unknown)));

            return false;
        }

        return $this->persist([
            'name' => $this->name,
            'subject' => $this->subject,
            'preheader' => $this->preheader ?: null,
            'from_name' => $this->fromName,
            'from_email' => $this->fromEmail,
            'reply_to' => $this->replyTo ?: null,
            'template_id' => $this->templateId,
            'blocks' => $this->blocks,
        ]);
    }

    private function saveAudience(): bool
    {
        $rules = [
            'audienceMode' => ['required', 'in:segment,filters,contacts'],
            'abEnabled' => ['boolean'],
        ];

        if ($this->audienceMode === 'segment') {
            $rules['segmentId'] = ['required', 'integer', 'exists:email_segments,id'];
        } elseif ($this->audienceMode === 'contacts') {
            $rules += [
                'contactListIds' => ['required', 'array', 'min:1'],
                'contactListIds.*' => ['required', 'integer', 'distinct', 'exists:email_contact_lists,id'],
                'includeOwners' => ['boolean'],
            ];
        } else {
            $rules += AudienceFilterData::rules('filters');
        }

        if ($this->abEnabled) {
            $rules += [
                'abSubjectB' => ['required', 'string', 'max:200', 'different:subject'],
                'abSamplePercent' => ['required', 'integer', 'min:10', 'max:50'],
                'abWaitHours' => ['required', 'integer', 'min:1', 'max:72'],
            ];
        }

        $this->validate($rules, attributes: [
            'segmentId' => 'segmento', 'abSubjectB' => 'assunto B', 'abSamplePercent' => 'amostra', 'abWaitHours' => 'espera',
        ]);

        return $this->persist([
            'segment_id' => $this->audienceMode === 'segment' ? $this->segmentId : null,
            'filters' => $this->audienceMode !== 'segment' ? $this->currentAudience()->toArray() : null,
            'ab_subject_b' => $this->abEnabled ? $this->abSubjectB : null,
            'ab_sample_percent' => $this->abEnabled ? $this->abSamplePercent : null,
            'ab_wait_hours' => $this->abEnabled ? $this->abWaitHours : null,
        ]);
    }

    private function persist(array $data): bool
    {
        try {
            $campaign = app(CampaignService::class)->saveDraft($this->campaign(), $data, auth()->user());
        } catch (LogicException $exception) {
            $this->errorNotice = $exception->getMessage();

            return false;
        }

        $this->campaignId = $campaign->id;
        $this->blocks = $campaign->blocks ?? $this->blocks;
        unset($this->checklist);

        return true;
    }

    private function campaign(): ?EmailCampaign
    {
        if (! $this->campaignId) {
            return null;
        }

        return EmailCampaign::campaigns()->with('segment')->find($this->campaignId);
    }

    private function currentAudience(): ?AudienceFilterData
    {
        if ($this->audienceMode === 'segment') {
            return $this->segmentId ? EmailSegment::find($this->segmentId)?->filterData() : null;
        }

        if ($this->audienceMode === 'contacts') {
            // Sem lista, não cair no público padrão de todos os usuários.
            $filters = AudienceFilterData::fromArray([
                'contact_lists' => $this->contactListIds,
                'include_owners' => $this->includeOwners,
            ]);

            return $filters->isContacts() ? $filters : null;
        }

        return $this->filterData();
    }

    private function parsedSchedule(): ?Carbon
    {
        if (blank($this->scheduledAt)) {
            return null;
        }

        try {
            $at = Carbon::createFromFormat('Y-m-d\TH:i', $this->scheduledAt, 'America/Sao_Paulo');
        } catch (\Throwable) {
            return null;
        }

        // O banco guarda no fuso da aplicação (config app.timezone), não em UTC.
        return $at && $at->isFuture() ? $at->setTimezone(config('app.timezone')) : null;
    }

    private function fillFrom(EmailCampaign $campaign): void
    {
        $this->campaignId = $campaign->id;
        $this->name = $campaign->name;
        $this->subject = (string) $campaign->subject;
        $this->preheader = (string) $campaign->preheader;
        $this->fromName = (string) ($campaign->from_name ?: config('marketing.from.name'));
        $this->fromEmail = (string) ($campaign->from_email ?: config('marketing.from.address'));
        $this->replyTo = (string) $campaign->reply_to;
        $this->templateId = $campaign->template_id;
        $this->blocks = $campaign->blocks ?? [];
        $audience = AudienceFilterData::fromArray($campaign->filters ?? []);
        $this->audienceMode = $campaign->segment_id ? 'segment' : ($audience->isContacts() ? 'contacts' : 'filters');
        $this->contactListIds = $audience->contactLists;
        $this->includeOwners = $audience->includeOwners;
        $this->segmentId = $campaign->segment_id;
        $this->resetFilters(AudienceFilterData::fromArray($campaign->filters ?? []));
        $this->abEnabled = filled($campaign->ab_subject_b);
        $this->abSubjectB = (string) $campaign->ab_subject_b;
        $this->abSamplePercent = (int) ($campaign->ab_sample_percent ?: 20);
        $this->abWaitHours = (int) ($campaign->ab_wait_hours ?: 4);

        if ($campaign->scheduled_at) {
            $this->sendMode = 'schedule';
            $this->scheduledAt = $campaign->scheduled_at->timezone('America/Sao_Paulo')->format('Y-m-d\TH:i');
        }
    }
}
