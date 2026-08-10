<?php

namespace App\Livewire\Admin\Branches;

use App\Models\Branch;
use App\Models\BranchPause;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

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

        BranchPause::create([
            'company_id' => $this->branch->company_id,
            'branch_id' => $this->branch->id,
            'reason' => $validated['reason'] ?: null,
            'recurring_annual' => $validated['recurring_annual'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        $this->reset(['reason', 'recurring_annual', 'starts_date', 'starts_time', 'ends_date', 'ends_time']);
        $this->forgetCaches();

        session()->flash('status', 'Pausa cadastrada.');
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

        $this->branch->pauses()->findOrFail($this->deletingId)->delete();
        $this->deletingId = null;
        $this->forgetCaches();

        session()->flash('status', 'Pausa removida.');
    }

    private function forgetCaches(): void
    {
        Cache::forget("branches:company:{$this->branch->company_id}");
        Cache::forget("open_branches:company:{$this->branch->company_id}");
        session()->forget('chat_state');
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
