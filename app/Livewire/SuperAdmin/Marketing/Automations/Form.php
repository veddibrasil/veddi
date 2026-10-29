<?php

namespace App\Livewire\SuperAdmin\Marketing\Automations;

use App\Enums\EmailAutomationTrigger;
use App\Livewire\SuperAdmin\Concerns\RequiresSuperAdmin;
use App\Models\EmailAutomation;
use App\Models\EmailTemplate;
use App\Services\Marketing\AutomationService;
use App\Services\Marketing\TemplateRenderer;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Automação: gatilho + sequência de e-mails (cada passo com espera, template e assunto). */
class Form extends Component
{
    use RequiresSuperAdmin;

    #[Locked]
    public ?int $automationId = null;

    public string $name = '';

    public string $trigger = 'abandoned_signup';

    public int $threshold = 2;

    public string $fromName = '';

    public string $fromEmail = '';

    public string $replyTo = '';

    /** @var list<array{id: ?int, delay_hours: int|string, template_id: int|string|null, subject: string, preheader: string}> */
    public array $steps = [];

    public function mount(?EmailAutomation $automation = null): void
    {
        if ($automation?->exists) {
            $automation->load('steps.campaign');
            $this->automationId = $automation->id;
            $this->name = $automation->name;
            $this->trigger = $automation->trigger->value;
            $this->threshold = $automation->threshold();
            $this->fromName = (string) $automation->from_name;
            $this->fromEmail = (string) $automation->from_email;
            $this->replyTo = (string) $automation->reply_to;
            $this->steps = $automation->steps->map(fn ($step) => [
                'id' => $step->id,
                'delay_hours' => $step->delay_hours,
                'template_id' => $step->campaign?->template_id,
                'subject' => (string) $step->campaign?->subject,
                'preheader' => (string) $step->campaign?->preheader,
            ])->all();

            return;
        }

        $this->fromName = (string) config('marketing.from.name');
        $this->fromEmail = (string) config('marketing.from.address');
        $this->replyTo = (string) config('marketing.reply_to');
        $this->addStep();
    }

    public function updatedTrigger(string $value): void
    {
        $this->threshold = EmailAutomationTrigger::tryFrom($value)?->defaultThreshold() ?? $this->threshold;
    }

    public function addStep(): void
    {
        if (count($this->steps) >= 10) {
            return;
        }

        $this->steps[] = ['id' => null, 'delay_hours' => $this->steps === [] ? 0 : 48, 'template_id' => null, 'subject' => '', 'preheader' => ''];
    }

    public function removeStep(int $index): void
    {
        unset($this->steps[$index]);
        $this->steps = array_values($this->steps);
    }

    public function save(AutomationService $automations, TemplateRenderer $renderer): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'trigger' => ['required', Rule::enum(EmailAutomationTrigger::class)],
            'threshold' => ['required', 'integer', 'min:1', 'max:720'],
            'fromName' => ['required', 'string', 'max:100'],
            'fromEmail' => ['required', 'email', 'max:150'],
            'replyTo' => ['nullable', 'email', 'max:150'],
            'steps' => ['required', 'array', 'min:1', 'max:10'],
            'steps.*.delay_hours' => ['required', 'integer', 'min:0', 'max:2160'],
            'steps.*.template_id' => ['required', 'integer', 'exists:email_templates,id'],
            'steps.*.subject' => ['required', 'string', 'max:200'],
            'steps.*.preheader' => ['nullable', 'string', 'max:200'],
        ], attributes: [
            'name' => 'nome', 'threshold' => 'limite do gatilho', 'fromName' => 'nome do remetente', 'fromEmail' => 'e-mail do remetente',
            'steps.*.delay_hours' => 'espera', 'steps.*.template_id' => 'template', 'steps.*.subject' => 'assunto',
        ]);

        $unknown = $renderer->unknownVariables(...collect($this->steps)->flatMap(fn ($step) => [$step['subject'], $step['preheader']])->all());

        if ($unknown !== []) {
            $this->addError('steps', 'Variáveis desconhecidas: '.implode(', ', array_map(fn ($name) => '{{'.$name.'}}', $unknown)));

            return;
        }

        $automation = $automations->save(
            $this->automationId ? EmailAutomation::findOrFail($this->automationId) : null,
            [
                'name' => $this->name,
                'trigger' => $this->trigger,
                'trigger_settings' => ['threshold' => $this->threshold],
                'from_name' => $this->fromName,
                'from_email' => $this->fromEmail,
                'reply_to' => $this->replyTo ?: null,
            ],
            array_map(fn (array $step) => [
                'id' => $step['id'] ?? null,
                'delay_hours' => (int) $step['delay_hours'],
                'template_id' => (int) $step['template_id'],
                'subject' => $step['subject'],
                'preheader' => $step['preheader'] ?: null,
            ], $this->steps),
            auth()->user(),
        );

        // Editar uma automação ativa não pode furar o checklist (ex.: remetente de domínio não verificado).
        if ($automation->active && ($problems = $automations->activationProblems($automation))) {
            $automations->setActive($automation, false);
            session()->flash('marketing_notice', 'Automação salva e pausada: '.implode(' ', $problems));
            $this->redirectRoute('superadmin.marketing.automations.index', navigate: true);

            return;
        }

        session()->flash('marketing_notice', $automation->active
            ? 'Automação salva.'
            : 'Automação salva. Ative na lista quando estiver pronta.');

        $this->redirectRoute('superadmin.marketing.automations.index', navigate: true);
    }

    public function render()
    {
        $automation = $this->automationId ? EmailAutomation::with('steps.campaign')->find($this->automationId) : null;

        return view('livewire.super-admin.marketing.automations.form', [
            'templates' => EmailTemplate::orderBy('name')->get(['id', 'name']),
            'triggers' => EmailAutomationTrigger::cases(),
            'currentTrigger' => EmailAutomationTrigger::tryFrom($this->trigger),
            'automation' => $automation,
        ])->layout('layouts.app', ['title' => 'Super Admin — Automação de e-mail']);
    }
}
