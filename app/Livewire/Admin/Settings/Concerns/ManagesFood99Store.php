<?php

namespace App\Livewire\Admin\Settings\Concerns;

use App\Models\BranchPause;
use App\Models\Food99Integration;
use App\Services\Food99\Food99StoreHours;
use App\Services\Food99\Food99StoreStatusSync;
use Livewire\Attributes\Locked;

/**
 * Abas Pausas e Horários da integração 99Food. A 99 não lista interrupções como o iFood: a
 * pausa atual vem do status da loja e as agendadas são as pausas da filial replicadas nela.
 * Os horários chegam por shop/detail e ficam gravados, então abrir a aba não gasta a cota de
 * 1 chamada por minuto.
 */
trait ManagesFood99Store
{
    /** @var array<int, array{day: int, start: string, end: string}> */
    public array $openingShifts = [];

    /** @var array<int, array{id: int, reason: ?string, period: string, state: string}> */
    #[Locked]
    public array $mirroredPauses = [];

    #[Locked]
    public ?int $confirmingPauseId = null;

    public bool $showRemovePauseModal = false;

    private function loadStoreState(?Food99Integration $integration): void
    {
        $this->openingShifts = app(Food99StoreHours::class)->shiftsFrom($integration?->business_hours);
        $this->mirroredPauses = $integration ? $this->mirroredPausesFor($integration) : [];
        $this->confirmingPauseId = null;
        $this->showRemovePauseModal = false;
    }

    /** @return array<int, array{id: int, reason: ?string, period: string, state: string}> */
    private function mirroredPausesFor(Food99Integration $integration): array
    {
        return BranchPause::where('company_id', $integration->company_id)
            ->where('branch_id', $integration->branch_id)
            ->where('food99_sync', true)
            ->whereIn('food99_sync_state', ['scheduled', 'closed', 'paused'])
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (BranchPause $pause) => [
                'id' => $pause->id,
                'reason' => $pause->reason,
                'period' => $pause->starts_at->timezone(config('app.timezone'))->format('d/m H:i').' até '.$pause->ends_at->timezone(config('app.timezone'))->format('d/m H:i'),
                'state' => $pause->food99_sync_state === 'scheduled' ? 'Agendada' : 'Em andamento',
            ])->all();
    }

    public function confirmRemovePause(int $pauseId): void
    {
        $this->context(requireShop: true);

        abort_unless(in_array($pauseId, array_column($this->mirroredPauses, 'id'), true), 404);

        $this->confirmingPauseId = $pauseId;
        $this->showRemovePauseModal = true;
    }

    /** Tira a pausa só da 99Food: ela continua valendo pro chat e pelo cadastro da filial. */
    public function removePauseFrom99(): void
    {
        [$company, $branch] = $this->context(requireShop: true);
        $this->showRemovePauseModal = false;

        $pause = BranchPause::where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->where('food99_sync', true)
            ->findOrFail($this->confirmingPauseId);

        if (app(Food99StoreStatusSync::class)->remove($pause)) {
            $pause->update(['food99_sync' => false, 'food99_sync_state' => null]);
            session()->flash('status', 'Pausa removida da 99Food. Ela continua valendo no painel e no chat.');
        } else {
            session()->flash('error', 'A 99Food não confirmou a reabertura da loja. Tente de novo em instantes.');
        }

        $this->loadForBranch($company, $branch);
    }

    public function addOpeningShift(int $day): void
    {
        $this->context(requireShop: true);
        abort_unless(isset(Food99StoreHours::WEEK_DAYS[$day]), 422);

        $hasShift = collect($this->openingShifts)->contains('day', $day);
        $this->openingShifts[] = ['day' => $day, 'start' => $hasShift ? '19:00' : '09:00', 'end' => $hasShift ? '23:00' : '18:00'];
    }

    public function removeOpeningShift(int $index): void
    {
        $this->context(requireShop: true);

        unset($this->openingShifts[$index]);
        $this->openingShifts = array_values($this->openingShifts);
    }

    /** Só preenche o editor: o envio continua no "Salvar". */
    public function fillHoursFromBranch(): void
    {
        [, $branch] = $this->context(requireShop: true);

        $this->openingShifts = app(Food99StoreHours::class)->shiftsFromBranch($branch);
        $this->resetErrorBag('openingShifts');
    }

    public function saveOpeningHours(): void
    {
        [$company, $branch, $integration] = $this->context(requireShop: true);

        $saved = $this->tryStoreAction(fn () => app(Food99StoreHours::class)->save($integration, $this->openingShifts));

        if ($saved) {
            session()->flash('status', 'Horários salvos na 99Food.');
            $this->loadForBranch($company, $branch);
        }
    }
}
