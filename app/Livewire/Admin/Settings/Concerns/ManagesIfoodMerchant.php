<?php

namespace App\Livewire\Admin\Settings\Concerns;

use App\Contracts\IfoodGatewayContract;
use App\Exceptions\IfoodApiException;
use App\Models\Branch;
use App\Models\BranchPause;
use App\Models\IfoodIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

trait ManagesIfoodMerchant
{
    #[Locked]
    public array $merchantStores = [];

    #[Locked]
    public bool $merchantStoresLoaded = false;

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

    /** Pausa aguardando confirmação de remoção (modal). */
    public ?string $confirmingInterruptionId = null;

    /** @var array<int, array{dayOfWeek: string, start: string, end: string}> */
    public array $openingShifts = [];

    public function consultMerchantStores(): void
    {
        $this->authorizeHomologationTools();
        $integration = $this->merchantIntegration();
        $this->merchantStores = [];
        $this->merchantStoresLoaded = false;
        $this->readMerchant(function () use ($integration) {
            $this->merchantStores = app(IfoodGatewayContract::class)->listMerchants($integration);
            $this->merchantStoresLoaded = true;
        });
    }

    public function consultMerchant(): void
    {
        $this->authorizeHomologationTools();
        $integration = $this->merchantIntegration();
        $this->merchantStores = [];
        $this->merchantStoresLoaded = false;
        $this->readMerchant(function () use ($integration) {
            $gateway = app(IfoodGatewayContract::class);
            $this->merchantStores = $gateway->listMerchants($integration);
            $this->merchantStoresLoaded = true;
            $this->merchantDetails = $gateway->getMerchantDetails($integration);
            $this->merchantStatus = $gateway->getMerchantStatus($integration);
        });
    }

    /** Resumo da loja (dados e disponibilidade) que a aba "Loja" carrega sozinha ao abrir. */
    public function loadStoreOverview(): void
    {
        $integration = $this->merchantIntegration();
        $this->readMerchant(function () use ($integration) {
            $gateway = app(IfoodGatewayContract::class);
            $this->merchantDetails = $gateway->getMerchantDetails($integration);
            $this->merchantStatus = $gateway->getMerchantStatus($integration);
            $this->rememberMerchantName($integration);
        });
    }

    /**
     * Conexão feita sem o módulo merchant fica sem nome (o iFood só devolveu o id); os detalhes
     * da loja trazem o nome, que passa a aparecer no cabeçalho e no Diagnóstico.
     */
    private function rememberMerchantName(IfoodIntegration $integration): void
    {
        $name = trim((string) ($this->merchantDetails['name'] ?? ''));
        if ($name === '' || $name === $integration->merchant_id || $name === $integration->merchant_name) {
            return;
        }

        $integration->update(['merchant_name' => mb_substr($name, 0, 255)]);
        $this->merchantName = $integration->merchant_name;
    }

    public function consultInterruptions(): void
    {
        $integration = $this->merchantIntegration();
        $this->interruptionsLoaded = false;
        $this->interruptions = [];
        $this->readMerchant(function () use ($integration) {
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
        ], [], [
            'interruptionDescription' => 'motivo',
            'interruptionStart' => 'início',
            'interruptionEnd' => 'fim',
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
            $gateway = app(IfoodGatewayContract::class);
            $gateway->createInterruption($integration, [
                'description' => $this->interruptionDescription,
                'start' => $start->format('Y-m-d\TH:i:s'),
                'end' => $end->format('Y-m-d\TH:i:s'),
            ]);
            $this->reset('interruptionDescription', 'interruptionStart', 'interruptionEnd');
            session()->flash('status', 'Pausa criada no iFood. A loja fica fechada no app do iFood durante o período.');
            // A pausa já existe no iFood: falha só ao atualizar a lista não vira erro ao lado do sucesso.
            try {
                $this->interruptions = $gateway->listInterruptions($integration);
                $this->interruptionsLoaded = true;
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    public function confirmRemoveInterruption(string $id): void
    {
        $this->merchantIntegration();
        $this->confirmingInterruptionId = $id;
    }

    public function removeStoreInterruption(string $id): void
    {
        $integration = $this->merchantIntegration();
        $this->confirmingInterruptionId = null;
        $this->merchantOperation(function () use ($integration, $id) {
            $gateway = app(IfoodGatewayContract::class);
            $current = $gateway->listInterruptions($integration);
            if (! collect($current)->contains('id', $id)) {
                $this->addError('merchantOperation', 'Essa pausa não está mais na loja selecionada. Consulte novamente.');

                return;
            }
            $gateway->deleteInterruption($integration, $id);
            // Pausa da filial espelhada nesta interrupção deixa de apontar pra ela (some o selo
            // "também no iFood" e excluir a pausa depois não tenta remover de novo).
            BranchPause::withoutGlobalScopes()->where('company_id', $integration->company_id)
                ->where('branch_id', $integration->branch_id)->where('ifood_interruption_id', $id)
                ->update(['ifood_interruption_id' => null]);
            // O iFood pode demorar a refletir a remoção: tira da lista local em vez de consultar de novo.
            $this->interruptions = array_values(array_filter($current, fn ($row) => ($row['id'] ?? null) !== $id));
            $this->interruptionsLoaded = true;
            session()->flash('status', 'Pausa removida no iFood. A loja volta a abrir no horário normal.');
        });
    }

    /** Período da pausa no fuso da loja, pra exibição. */
    public function interruptionPeriod(array $interruption): string
    {
        $format = fn ($value) => is_string($value) && $value !== ''
            ? CarbonImmutable::parse($value, config('ifood.timezone'))->timezone(config('ifood.timezone'))->format('d/m/Y H:i')
            : '—';

        return $format($interruption['start'] ?? null).' até '.$format($interruption['end'] ?? null);
    }

    public function consultOpeningHours(): void
    {
        $integration = $this->merchantIntegration();
        $this->hoursBranchId = null;
        $this->readMerchant(function () use ($integration) {
            $response = app(IfoodGatewayContract::class)->getOpeningHours($integration);
            $shifts = $response['shifts'] ?? collect($response)->flatMap(fn ($row) => $row['shifts'] ?? [])->all();
            $this->openingShifts = array_map(fn ($shift) => [
                'dayOfWeek' => $shift['dayOfWeek'],
                'start' => substr($shift['start'], 0, 5),
                'end' => $this->shiftEnd(substr($shift['start'], 0, 5), (int) $shift['duration']),
            ], $shifts);
            $this->hoursBranchId = $integration->branch_id;
        });
    }

    public function addOpeningShift(string $day = 'MONDAY'): void
    {
        $this->merchantIntegration();
        $day = array_key_exists($day, $this->weekDays()) ? $day : 'MONDAY';
        $hasShift = collect($this->openingShifts)->contains('dayOfWeek', $day);
        $this->openingShifts[] = ['dayOfWeek' => $day, 'start' => $hasShift ? '19:00' : '09:00', 'end' => $hasShift ? '23:00' : '18:00'];
    }

    public function removeOpeningShift(int $index): void
    {
        $this->merchantIntegration();
        unset($this->openingShifts[$index]);
        $this->openingShifts = array_values($this->openingShifts);
    }

    public function fillHomologationHours(): void
    {
        $this->authorizeHomologationTools();
        $integration = $this->merchantIntegration();
        abort_unless($this->hoursBranchId === $integration->branch_id, 422);
        $this->openingShifts = array_values(array_filter($this->openingShifts, fn ($shift) => ! in_array($shift['dayOfWeek'], ['SATURDAY', 'SUNDAY'])));
        foreach ([['SATURDAY', '10:00', 540], ['SUNDAY', '09:00', 180], ['SUNDAY', '13:00', 180], ['SUNDAY', '17:00', 360]] as [$day, $start, $duration]) {
            $this->openingShifts[] = ['dayOfWeek' => $day, 'start' => $start, 'end' => $this->shiftEnd($start, $duration)];
        }
    }

    /**
     * Preenche o editor com os horários de funcionamento da filial (dias e horário de cada dia),
     * pra iFood e painel abrirem e fecharem juntos. Só preenche: o envio continua no "Salvar".
     */
    public function fillHoursFromBranch(): void
    {
        $integration = $this->merchantIntegration();
        abort_unless($this->hoursBranchId === $integration->branch_id, 422);
        $branch = Branch::withoutGlobalScopes()->where('company_id', $integration->company_id)->findOrFail($integration->branch_id);

        // Branch usa o dia da semana do PHP (0 = domingo).
        $days = [0 => 'SUNDAY', 1 => 'MONDAY', 2 => 'TUESDAY', 3 => 'WEDNESDAY', 4 => 'THURSDAY', 5 => 'FRIDAY', 6 => 'SATURDAY'];
        $shifts = [];
        foreach ($days as $index => $day) {
            if ($branch->available_days !== null && ! in_array($index, array_map('intval', $branch->available_days), true)) {
                continue;
            }
            $hours = $branch->hoursForDay($index);
            if (! $hours['opens_at'] || ! $hours['closes_at']) {
                continue;
            }
            $shifts[] = ['dayOfWeek' => $day, 'start' => substr((string) $hours['opens_at'], 0, 5), 'end' => substr((string) $hours['closes_at'], 0, 5)];
        }

        $this->openingShifts = $shifts;
        $this->resetErrorBag('openingShifts');
    }

    public function saveOpeningHours(): void
    {
        $integration = $this->merchantIntegration();
        abort_unless($this->hoursBranchId === $integration->branch_id, 422);
        $this->validate([
            'openingShifts' => ['present', 'array', 'max:100'],
            'openingShifts.*.dayOfWeek' => ['required', Rule::in(array_keys($this->weekDays()))],
            'openingShifts.*.start' => ['required', 'date_format:H:i'],
            'openingShifts.*.end' => ['required', 'date_format:H:i'],
        ], [
            'openingShifts.*.start.*' => 'Informe a abertura no formato HH:MM.',
            'openingShifts.*.end.*' => 'Informe o fechamento no formato HH:MM.',
        ]);
        $intervals = [];
        $days = array_keys($this->weekDays());
        $shifts = [];
        foreach ($this->openingShifts as $shift) {
            $duration = $this->shiftDuration($shift['start'], $shift['end']);
            [$hour, $minute] = array_map('intval', explode(':', $shift['start']));
            $start = array_search($shift['dayOfWeek'], $days) * 1440 + $hour * 60 + $minute;
            // Copiar a semana permite detectar sobreposição de domingo para segunda.
            foreach ([$start, $start + 10080] as $point) {
                $intervals[] = [$point, $point + $duration];
            }
            $shifts[] = ['dayOfWeek' => $shift['dayOfWeek'], 'start' => $shift['start'].':00', 'duration' => $duration];
        }
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        for ($i = 1; $i < count($intervals); $i++) {
            if ($intervals[$i][0] < $intervals[$i - 1][1]) {
                $this->addError('openingShifts', 'Os turnos não podem ter horários sobrepostos.');

                return;
            }
        }
        $this->merchantOperation(function () use ($integration, $shifts) {
            app(IfoodGatewayContract::class)->setOpeningHours($integration, $shifts);
            session()->flash('status', 'Horários enviados ao iFood. Consulte novamente para conferir como ficaram.');
        });
    }

    protected function weekDays(): array
    {
        return ['MONDAY' => 'Segunda', 'TUESDAY' => 'Terça', 'WEDNESDAY' => 'Quarta', 'THURSDAY' => 'Quinta', 'FRIDAY' => 'Sexta', 'SATURDAY' => 'Sábado', 'SUNDAY' => 'Domingo'];
    }

    /** Fechamento igual ou antes da abertura passa da meia-noite (igual = 24 horas). */
    private function shiftDuration(string $start, string $end): int
    {
        $toMinutes = fn (string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $duration = $toMinutes($end) - $toMinutes($start);

        return $duration <= 0 ? $duration + 1440 : $duration;
    }

    private function shiftEnd(string $start, int $duration): string
    {
        $minutes = ((int) substr($start, 0, 2) * 60 + (int) substr($start, 3, 2) + $duration) % 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Ferramentas do roteiro de homologação só existem com IFOOD_HOMOLOGATION_MODE ligado. */
    private function authorizeHomologationTools(): void
    {
        abort_unless((bool) config('ifood.homologation_mode'), 403);
    }

    private function merchantIntegration(): IfoodIntegration
    {
        $company = app('current.company');
        abort_unless($company->canUseIfoodIntegration(), 403);
        $this->authorizeIfoodManagement();

        return IfoodIntegration::where('company_id', $company->id)->where('branch_id', $this->branchId)
            ->whereNotNull('merchant_id')->whereIn('status', ['active', 'paused'])->firstOrFail();
    }

    /**
     * Consulta que a aba faz sozinha ao abrir: não apaga o aviso da ação anterior (ex.: "conectada,
     * sincronizando o cardápio"), que sumiria na primeira ida ao servidor.
     */
    private function readMerchant(\Closure $action): void
    {
        $this->merchantOperation($action, clearFlash: false);
    }

    private function merchantOperation(\Closure $action, bool $clearFlash = true): void
    {
        $this->resetErrorBag('merchantOperation');
        if ($clearFlash) {
            session()->forget(['error', 'status']);
        }
        try {
            $action();
            $this->merchantCheckedAt = now()->timezone(config('ifood.timezone'))->format('d/m/Y H:i:s P');
        } catch (\Throwable $e) {
            report($e);
            $this->addError('merchantOperation', $e instanceof IfoodApiException
                ? $e->userMessage()
                : 'Não foi possível concluir a operação no iFood. Tente de novo em instantes.');
        }
    }
}
