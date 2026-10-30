<?php

namespace App\Livewire\Admin\Branches;

use App\Exceptions\IfoodApiException;
use App\Models\Branch;
use App\Models\BranchPause;
use App\Services\Food99\Food99StoreStatusSync;
use App\Services\Ifood\IfoodBranchPauseSync;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class Pauses extends Component
{
    public Branch $branch;

    #[Locked]
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

    /** Replica a pausa como fechamento/pausa da loja na 99Food (só aparece com a 99Food conectada). */
    public bool $close_in_99food = false;

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
            $this->close_in_99food = false;
            $this->resetErrorBag(['close_in_ifood', 'close_in_99food']);
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
        $this->authorizeSave();

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

        $food99Sync = app(Food99StoreStatusSync::class);
        $food99Integration = $this->close_in_99food ? $food99Sync->integrationFor($this->branch) : null;
        if ($food99Integration && ($reason = $food99Sync->rejectionReason($pause))) {
            $this->addError('close_in_99food', $reason);

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

        if ($food99Integration) {
            try {
                $food99Sync->mirror($pause, $food99Integration);
                $message .= ' Na 99Food a loja também fecha nesse período.';
            } catch (Throwable $e) {
                report($e);
                session()->flash('error', 'A pausa foi cadastrada no painel, mas a 99Food não aceitou fechar a loja. '.\App\Exceptions\Food99ApiException::messageFor($e).' Tente pela tela da integração 99Food, na aba Pausas.');
            }
        }

        $this->reset(['reason', 'recurring_annual', 'starts_date', 'starts_time', 'ends_date', 'ends_time', 'close_in_ifood', 'close_in_99food']);
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
        $this->authorizeSave();

        $pause = $this->branch->pauses()->findOrFail($this->deletingId);
        $removedFromIfood = app(IfoodBranchPauseSync::class)->remove($pause);
        $reopenedIn99food = app(Food99StoreStatusSync::class)->remove($pause);
        $pause->delete();
        $this->deletingId = null;
        $this->forgetCaches();

        session()->flash('status', 'Pausa removida.');
        if (! $reopenedIn99food) {
            session()->flash('error', 'A pausa foi removida do painel, mas não conseguimos reabrir a loja na 99Food. Reabra pela tela da integração 99Food, na aba Loja.');
        }
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
    /**
     * canSave vem do mount e só desenha a tela; quem grava é reconferido aqui (permissão do
     * papel e filial do gerente), sem confiar no estado que volta do navegador.
     */
    private function authorizeSave(): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return;
        }

        $company = app()->bound('current.company') ? app('current.company') : null;

        abort_unless(
            $this->canSave
                && $company
                && (int) $this->branch->company_id === (int) $company->id
                && $user->hasPermission('branches.update', $company)
                && $user->canAccessBranch($company, $this->branch->id),
            403
        );
    }

    public function getFood99ConnectedProperty(): bool
    {
        return app(Food99StoreStatusSync::class)->integrationFor($this->branch) !== null;
    }

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
