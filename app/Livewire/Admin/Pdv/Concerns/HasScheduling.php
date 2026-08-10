<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Models\Branch;
use Carbon\Carbon;
use Livewire\Attributes\Computed;

trait HasScheduling
{
    #[Computed]
    public function schedulingEnabled(): bool
    {
        $company = app()->bound('current.company') ? app('current.company') : null;

        return (bool) $company?->schedulingEnabled();
    }

    #[Computed]
    public function availableScheduleTimeSlots(): array
    {
        if (! $this->selectedBranchId || ! $this->scheduleDate) {
            return [];
        }

        $branch = Branch::find($this->selectedBranchId);
        if (! $branch || ! $branch->opens_at || ! $branch->closes_at) {
            return [];
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $this->scheduleDate, config('app.timezone'));
        } catch (\Exception) {
            return [];
        }

        $company = app()->bound('current.company') ? app('current.company') : null;
        $minMinutes = $company?->schedule_min_advance_minutes ?? 60;

        return $branch->scheduleTimeSlotsForDate($date, $minMinutes);
    }

    public function updatedIsScheduled(): void
    {
        $this->resetErrorBag('scheduledAt');

        if (! $this->isScheduled) {
            $this->scheduleDate = '';
            $this->scheduleTime = '';
        }
    }

    public function updatedScheduleDate(): void
    {
        unset($this->availableScheduleTimeSlots);
        $this->scheduleTime = $this->availableScheduleTimeSlots[0] ?? '';
    }

    /** Mesma validação usada no agendamento do chat público (dia/horário de funcionamento da filial). */
    private function resolveScheduledAt(Branch $branch): ?Carbon
    {
        if (! $this->isScheduled) {
            return null;
        }

        $this->resetErrorBag('scheduledAt');

        $company = app()->bound('current.company') ? app('current.company') : null;
        $minMinutes = $company?->schedule_min_advance_minutes ?? 60;

        if (empty($this->scheduleDate) || empty($this->scheduleTime)) {
            $this->addError('scheduledAt', 'Informe a data e o horário do agendamento.');

            return null;
        }

        try {
            $scheduled = Carbon::createFromFormat('Y-m-d H:i', $this->scheduleDate.' '.$this->scheduleTime, config('app.timezone'));
        } catch (\Exception) {
            $this->addError('scheduledAt', 'Data/hora inválida.');

            return null;
        }

        if ($scheduled->isPast()) {
            $this->addError('scheduledAt', 'O horário deve ser no futuro.');

            return null;
        }

        $minTime = now(config('app.timezone'))->addMinutes($minMinutes);
        if ($scheduled->lt($minTime)) {
            $this->addError('scheduledAt', "Agende com pelo menos {$minMinutes} minutos de antecedência.");

            return null;
        }

        $dayOfWeek = (int) $scheduled->format('w');
        $timeStr = $scheduled->format('H:i');

        $availableDays = $branch->available_days;
        if ($availableDays !== null && ! in_array($dayOfWeek, $availableDays)) {
            $this->addError('scheduledAt', 'A filial não atende no dia selecionado.');

            return null;
        }

        if (! $branch->isWithinSchedulingSlot($dayOfWeek, $timeStr)) {
            $this->addError('scheduledAt', 'Não há agendamento disponível nesse horário.');

            return null;
        }

        if ($pause = $branch->activePauseAt($scheduled)) {
            $reasonText = $pause->reason ? " Motivo: {$pause->reason}." : '';
            $this->addError('scheduledAt', "A filial estará fechada nesse período.{$reasonText}");

            return null;
        }

        return $scheduled;
    }

    private function resetScheduleState(): void
    {
        $this->isScheduled = false;
        $this->scheduleDate = '';
        $this->scheduleTime = '';
    }
}
