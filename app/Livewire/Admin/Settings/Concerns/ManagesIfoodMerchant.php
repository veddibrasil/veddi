<?php

namespace App\Livewire\Admin\Settings\Concerns;

use App\Contracts\IfoodGatewayContract;
use App\Models\IfoodIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

trait ManagesIfoodMerchant
{
    #[Locked]
    public array $merchantStores = [];

    #[Locked]
    public array $merchantDetails = [];

    #[Locked]
    public array $merchantStatus = [];

    #[Locked]
    public array $interruptions = [];

    #[Locked]
    public bool $interruptionsLoaded = false;

    #[Locked]
    public ?int $hoursBranchId = null;

    #[Locked]
    public ?string $merchantCheckedAt = null;

    public string $interruptionDescription = '';

    public string $interruptionStart = '';

    public string $interruptionEnd = '';

    public array $openingShifts = [];

    public function consultMerchant(): void
    {
        $integration = $this->merchantIntegration();
        $this->merchantOperation(function () use ($integration) {
            $gateway = app(IfoodGatewayContract::class);
            $this->merchantStores = $gateway->listMerchants($integration);
            $this->merchantDetails = $gateway->getMerchantDetails($integration);
            $this->merchantStatus = $gateway->getMerchantStatus($integration);
        });
    }

    public function consultInterruptions(): void
    {
        $integration = $this->merchantIntegration();
        $this->interruptionsLoaded = false;
        $this->interruptions = [];
        $this->merchantOperation(function () use ($integration) {
            $this->interruptions = app(IfoodGatewayContract::class)->listInterruptions($integration);
            $this->interruptionsLoaded = true;
        });
    }

    public function createStoreInterruption(): void
    {
        $integration = $this->merchantIntegration();
        $this->validate([
            'interruptionDescription' => ['required', 'string', 'max:255'],
            'interruptionStart' => ['required', 'date_format:Y-m-d\TH:i'],
            'interruptionEnd' => ['required', 'date_format:Y-m-d\TH:i', 'after:interruptionStart'],
        ]);
        $start = CarbonImmutable::parse($this->interruptionStart, config('ifood.timezone'));
        $end = CarbonImmutable::parse($this->interruptionEnd, config('ifood.timezone'));
        if ($end->isPast() || $start->diffInMinutes($end) < 1 || $start->diffInMinutes($end) > 10080) {
            $this->addError('interruptionEnd', 'A pausa deve terminar no futuro e durar entre 1 minuto e 7 dias.');

            return;
        }
        $this->merchantOperation(function () use ($integration, $start, $end) {
            // O iFood interpreta start/end no fuso da loja e descarta o offset enviado;
            // com offset, 09:00-03:00 virava 12:00 na loja. Envia o horário de parede.
            app(IfoodGatewayContract::class)->createInterruption($integration, [
                'description' => $this->interruptionDescription,
                'start' => $start->format('Y-m-d\TH:i:s'),
                'end' => $end->format('Y-m-d\TH:i:s'),
            ]);
            session()->flash('status', 'Pausa criada no iFood. Consulte as pausas e confira no Portal do Parceiro.');
            $this->interruptions = [];
            $this->interruptionsLoaded = false;
        });
    }

    public function removeStoreInterruption(string $id): void
    {
        $integration = $this->merchantIntegration();
        $this->merchantOperation(function () use ($integration, $id) {
            $gateway = app(IfoodGatewayContract::class);
            $current = $gateway->listInterruptions($integration);
            if (! collect($current)->contains('id', $id)) {
                $this->addError('merchantOperation', 'Essa pausa não está mais na loja selecionada. Consulte novamente.');

                return;
            }
            $gateway->deleteInterruption($integration, $id);
            $this->interruptions = [];
            $this->interruptionsLoaded = false;
            session()->flash('status', 'Pausa removida no iFood. Consulte novamente para acompanhar a atualização.');
        });
    }

    public function consultOpeningHours(): void
    {
        $integration = $this->merchantIntegration();
        $this->hoursBranchId = null;
        $this->merchantOperation(function () use ($integration) {
            $response = app(IfoodGatewayContract::class)->getOpeningHours($integration);
            $shifts = $response['shifts'] ?? collect($response)->flatMap(fn ($row) => $row['shifts'] ?? [])->all();
            $this->openingShifts = array_map(fn ($shift) => [
                'dayOfWeek' => $shift['dayOfWeek'], 'start' => substr($shift['start'], 0, 5), 'duration' => $shift['duration'],
            ], $shifts);
            $this->hoursBranchId = $integration->branch_id;
        });
    }

    public function addOpeningShift(): void
    {
        $this->merchantIntegration();
        $this->openingShifts[] = ['dayOfWeek' => 'MONDAY', 'start' => '09:00', 'duration' => 180];
    }

    public function removeOpeningShift(int $index): void
    {
        $this->merchantIntegration();
        unset($this->openingShifts[$index]);
        $this->openingShifts = array_values($this->openingShifts);
    }

    public function fillHomologationHours(): void
    {
        $integration = $this->merchantIntegration();
        abort_unless($this->hoursBranchId === $integration->branch_id, 422);
        $this->openingShifts = array_values(array_filter($this->openingShifts, fn ($shift) => ! in_array($shift['dayOfWeek'], ['SATURDAY', 'SUNDAY'])));
        foreach ([['SATURDAY', '10:00', 540], ['SUNDAY', '09:00', 180], ['SUNDAY', '13:00', 180], ['SUNDAY', '17:00', 360]] as [$day, $start, $duration]) {
            $this->openingShifts[] = ['dayOfWeek' => $day, 'start' => $start, 'duration' => $duration];
        }
    }

    public function saveOpeningHours(): void
    {
        $integration = $this->merchantIntegration();
        abort_unless($this->hoursBranchId === $integration->branch_id, 422);
        $this->validate([
            'openingShifts' => ['present', 'array', 'max:100'],
            'openingShifts.*.dayOfWeek' => ['required', Rule::in(array_keys($this->weekDays()))],
            'openingShifts.*.start' => ['required', 'date_format:H:i'],
            'openingShifts.*.duration' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);
        $intervals = [];
        $days = array_keys($this->weekDays());
        foreach ($this->openingShifts as $shift) {
            [$hour, $minute] = array_map('intval', explode(':', $shift['start']));
            $start = array_search($shift['dayOfWeek'], $days) * 1440 + $hour * 60 + $minute;
            // Copiar a semana permite detectar sobreposição de domingo para segunda.
            foreach ([$start, $start + 10080] as $point) {
                $intervals[] = [$point, $point + (int) $shift['duration']];
            }
        }
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        for ($i = 1; $i < count($intervals); $i++) {
            if ($intervals[$i][0] < $intervals[$i - 1][1]) {
                $this->addError('openingShifts', 'Os turnos não podem ter horários sobrepostos.');

                return;
            }
        }
        $shifts = array_map(fn ($shift) => ['dayOfWeek' => $shift['dayOfWeek'], 'start' => $shift['start'].':00', 'duration' => (int) $shift['duration']], $this->openingShifts);
        $this->merchantOperation(function () use ($integration, $shifts) {
            app(IfoodGatewayContract::class)->setOpeningHours($integration, $shifts);
            session()->flash('status', 'Horários enviados ao iFood. Consulte novamente e confira no Portal do Parceiro.');
        });
    }

    protected function weekDays(): array
    {
        return ['MONDAY' => 'Segunda', 'TUESDAY' => 'Terça', 'WEDNESDAY' => 'Quarta', 'THURSDAY' => 'Quinta', 'FRIDAY' => 'Sexta', 'SATURDAY' => 'Sábado', 'SUNDAY' => 'Domingo'];
    }

    private function merchantIntegration(): IfoodIntegration
    {
        $company = app('current.company');
        abort_unless($company->canUseIfoodIntegration(), 403);

        return IfoodIntegration::where('company_id', $company->id)->where('branch_id', $this->branchId)
            ->whereNotNull('merchant_id')->whereIn('status', ['active', 'paused'])->firstOrFail();
    }

    private function merchantOperation(\Closure $action): void
    {
        $this->resetErrorBag('merchantOperation');
        session()->forget(['error', 'status']);
        try {
            $action();
            $this->merchantCheckedAt = now()->timezone(config('ifood.timezone'))->format('d/m/Y H:i:s P');
        } catch (\Throwable $e) {
            report($e);
            $this->addError('merchantOperation', 'Não foi possível concluir a operação no iFood. Consulte os dados antes de tentar novamente.');
        }
    }
}
