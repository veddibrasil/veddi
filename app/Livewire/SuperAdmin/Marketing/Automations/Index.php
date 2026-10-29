<?php

namespace App\Livewire\SuperAdmin\Marketing\Automations;

use App\Livewire\SuperAdmin\Concerns\RequiresSuperAdmin;
use App\Models\EmailAutomation;
use App\Models\EmailAutomationEnrollment;
use App\Services\Marketing\AutomationService;
use App\Services\Marketing\DefaultEmailAutomations;
use Livewire\Component;

class Index extends Component
{
    use RequiresSuperAdmin;

    public ?string $notice = null;

    public ?string $errorNotice = null;

    public function mount(): void
    {
        $this->notice = session('marketing_notice');
    }

    public function toggle(int $automationId, AutomationService $automations): void
    {
        $this->errorNotice = null;
        $automation = EmailAutomation::with('steps.campaign')->findOrFail($automationId);

        if (! $automation->active && ($problems = $automations->activationProblems($automation))) {
            $this->errorNotice = 'Não dá para ativar: '.implode(' ', $problems);

            return;
        }

        $automations->setActive($automation, ! $automation->active);
        $this->notice = $automation->active ? 'Automação ativada. Roda a cada 15 minutos.' : 'Automação pausada.';
    }

    /** Adiciona as automações prontas (pausadas) e os templates que elas usam, se faltarem. */
    public function installDefaults(DefaultEmailAutomations $defaults): void
    {
        $this->errorNotice = null;
        $created = $defaults->install(auth()->user());

        $this->notice = match ($created) {
            0 => 'As automações padrão já estão na lista.',
            1 => '1 automação padrão adicionada, pausada. Revise e ative quando estiver pronta.',
            default => "{$created} automações padrão adicionadas, pausadas. Revise e ative quando estiverem prontas.",
        };
    }

    public function render()
    {
        $automations = EmailAutomation::withCount([
            'steps',
            'enrollments as active_count' => fn ($query) => $query->where('status', EmailAutomationEnrollment::STATUS_ACTIVE),
            'enrollments as completed_count' => fn ($query) => $query->where('status', EmailAutomationEnrollment::STATUS_COMPLETED),
            'enrollments as exited_count' => fn ($query) => $query->where('status', EmailAutomationEnrollment::STATUS_EXITED),
        ])->orderBy('name')->get();

        return view('livewire.super-admin.marketing.automations.index', ['automations' => $automations])
            ->layout('layouts.app', ['title' => 'Super Admin — Automações de e-mail']);
    }
}
