<?php

namespace App\Livewire\Admin\Branches;

use App\Exceptions\IfoodApiException;
use App\Models\Branch;
use App\Models\BranchPause;
use App\Services\Ifood\IfoodBranchPauseSync;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Throwable;

class Pauses extends Component
{
    public Branch $branch;

    public bool $canSave = false;

    public string $reason = '';

    public bool $recurring_annual = false;

    public ?string $starts_date = null;

    public ?string $starts_time = null;

    public ?string $ends_date = null;

    public ?string $ends_time = null;

    public ?int $deletingId = null;

    /** Replica a pausa como interrupção da loja no iFood (só aparece com o iFood conectado). */
    public bool $close_in_ifood = false;

    public function mount(Branch $branch): void
    {
        $this->branch = $branch;

        $user = auth()->user();
        if ($user->isSuperAdmin()) {
            $this->canSave = true;

            return;
        }

        if (app()->bound('current.company')) {
            $company = app('current.company');
            $this->canSave = $user->hasPermission('branches.update', $company);

            if ($user->isBranchScoped($company) && $user->branchIdForCompany($company) !== $branch->id) {
                abort(403);
            }
        }
    }

    /** O iFood não tem pausa recorrente: marcar "repetir todo ano" desmarca o iFood (a caixa fica desabilitada). */
    public function updatedRecurringAnnual(bool $value): void
    {
        if ($value) {
            $this->close_in_ifood = false;
            $this->resetErrorBag('close_in_ifood');
        }
    }

    protected function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:150'],
            'recurring_annual' => ['boolean'],
            'starts_date' => ['required', 'date_format:Y-m-d'],
            'starts_time' => ['nullable', 'date_format:H:i'],
            'ends_date' => ['required', 'date_format:Y-m-d'],
            'ends_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    protected function messages(): array
    {
        return [
            'starts_date.required' => 'Informe a data de início.',
            'ends_date.required' => 'Informe a data de término.',
        ];
    }

    public function create(): void
    {
        abort_unless($this->canSave, 403);

        $validated = $this->validate($this->rules(), $this->messages());

        $startsAt = $validated['starts_date'].' '.($validated['starts_time'] ?: '00:00').':00';
        $endsAt = $validated['ends_date'].' '.($validated['ends_time'] ?: '23:59').':59';

        if (! $validated['recurring_annual'] && $endsAt <= $startsAt) {
            $this->addError('ends_date', 'O término deve ser após o início.');

            return;
        }

        $pause = new BranchPause([
            'company_id' => $this->branch->company_id,
            'branch_id' => $this->branch->id,
            'reason' => $validated['reason'] ?: null,
            'recurring_annual' => $validated['recurring_annual'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        $ifoodSync = app(IfoodBranchPauseSync::class);
        $integration = $this->close_in_ifood ? $ifoodSync->integrationFor($this->branch) : null;
        if ($integration && ($reason = $ifoodSync->rejectionReason($pause))) {
            $this->addError('close_in_ifood', $reason);

            return;
        }

        $pause->save();
        $message = 'Pausa cadastrada.';

        if ($integration) {
            try {
                $ifoodSync->mirror($pause, $integration);
                $message = 'Pausa cadastrada. A loja também fica fechada no iFood nesse período.';
            } catch (Throwable $e) {
                report($e);
                $reason = $e instanceof IfoodApiException ? ' '.$e->userMessage() : '';
                session()->flash('error', "A pausa foi cadastrada no painel, mas o iFood não aceitou o fechamento da loja.{$reason} Tente pela tela da integração iFood, na aba Pausas.");
            }
        }

        $this->reset(['reason', 'recurring_annual', 'starts_date', 'starts_time', 'ends_date', 'ends_time', 'close_in_ifood']);
        $this->forgetCaches();

        session()->flash('status', $message);
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
    }

    public function delete(): void
    {
        abort_unless($this->canSave, 403);

        $pause = $this->branch->pauses()->findOrFail($this->deletingId);
        $removedFromIfood = app(IfoodBranchPauseSync::class)->remove($pause);
        $pause->delete();
        $this->deletingId = null;
        $this->forgetCaches();

        session()->flash('status', 'Pausa removida.');
        if (! $removedFromIfood) {
            session()->flash('error', 'A pausa foi removida do painel, mas não conseguimos reabrir a loja no iFood. Remova a pausa pela tela da integração iFood, na aba Pausas.');
        }
    }

    private function forgetCaches(): void
    {
        Cache::forget("branches:company:{$this->branch->company_id}");
        Cache::forget("open_branches:company:{$this->branch->company_id}");
        session()->forget('chat_state');
    }

    /** Filial com iFood conectado: oferece fechar a loja no iFood junto. */
    public function getIfoodConnectedProperty(): bool
    {
        return app(IfoodBranchPauseSync::class)->integrationFor($this->branch) !== null;
    }

    public function getPausesProperty()
    {
        return $this->branch->pauses()->orderByDesc('starts_at')->get();
    }

    public function render()
    {
        return view('livewire.admin.branches.pauses')
            ->layout('layouts.app', ['title' => 'Pausas e Feriados — '.$this->branch->name]);
    }
}
