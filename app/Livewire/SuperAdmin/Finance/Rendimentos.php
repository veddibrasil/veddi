<?php

namespace App\Livewire\SuperAdmin\Finance;

use App\Models\PlatformYieldSnapshot;
use App\Services\Finance\YieldTrackingService;
use App\Services\Payment\StarkService;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Rendimentos extends Component
{
    public int $year;

    public int $month;

    public function mount(): void
    {
        $this->year = now()->year;
        $this->month = now()->month;
    }

    #[Computed]
    public function monthlyYield(): float
    {
        return app(YieldTrackingService::class)->monthlyYield($this->year, $this->month);
    }

    #[Computed]
    public function annualProjection(): float
    {
        return app(YieldTrackingService::class)->annualProjection();
    }

    #[Computed]
    public function currentStarkBalance(): float
    {
        try {
            return app(StarkService::class)->getBalance();
        } catch (\Throwable) {
            return 0.0;
        }
    }

    public function previousMonth(): void
    {
        $date = \Carbon\Carbon::createFromDate($this->year, $this->month, 1)->subMonth();
        $this->year = $date->year;
        $this->month = $date->month;
    }

    public function nextMonth(): void
    {
        $date = \Carbon\Carbon::createFromDate($this->year, $this->month, 1)->addMonth();
        $this->year = $date->year;
        $this->month = $date->month;
    }

    public function render(): View
    {
        $snapshots = PlatformYieldSnapshot::forMonth($this->year, $this->month)
            ->orderBy('snapshot_date')
            ->get();

        return view('livewire.super-admin.finance.rendimentos', compact('snapshots'))
            ->layout('layouts.app', ['title' => 'Rendimentos CDI']);
    }
}
