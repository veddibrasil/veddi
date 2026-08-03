<?php

namespace App\Livewire\Admin\Branches;

use App\Contracts\PrinterServiceInterface;
use App\Models\Branch;
use App\Models\BranchPrinter;
use Illuminate\Support\Collection;
use Livewire\Component;

class PrinterSettings extends Component
{
    public Branch $branch;

    public bool $canSave = false;

    public ?int $editingId = null;

    public string $station = 'geral';

    public string $name = '';

    public string $ipAddress = '';

    public string $port = '9100';

    public string $paperWidth = '80';

    public bool $autoPrint = false;

    public bool $printFiscalNote = false;

    public bool $active = true;

    public ?int $testResultFor = null;

    public ?bool $testResult = null;

    protected function rules(): array
    {
        return [
            'station' => ['required', 'in:'.implode(',', BranchPrinter::STATIONS)],
            'name' => ['nullable', 'string', 'max:100'],
            'ipAddress' => ['required', 'ip'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'paperWidth' => ['required', 'in:58,80'],
            'autoPrint' => ['boolean'],
            'printFiscalNote' => ['boolean'],
            'active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'ipAddress.required' => 'Informe o endereço IP da impressora.',
            'ipAddress.ip' => 'Informe um endereço IP válido.',
            'port.required' => 'Informe a porta da impressora.',
        ];
    }

    public function mount(Branch $branch): void
    {
        $this->branch = $branch;

        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            $this->canSave = true;
        } elseif (app()->bound('current.company')) {
            $company = app('current.company');
            $this->canSave = $user->hasPermission('branches.update', $company);

            if ($user->isBranchScoped($company) && $user->branchIdForCompany($company) !== $branch->id) {
                abort(403);
            }
        }

        $this->resetForm();
    }

    public function getPrintersProperty(): Collection
    {
        // Ordena pela posição em STATIONS via PHP (não orderByRaw com FIELD()) porque
        // os testes rodam em sqlite, que não tem essa função — só existe no MySQL.
        $order = array_flip(BranchPrinter::STATIONS);

        return $this->branch->printers()->get()
            ->sortBy(fn (BranchPrinter $printer) => $order[$printer->station] ?? 999)
            ->values();
    }

    public function getAvailableStationsProperty(): array
    {
        $used = $this->branch->printers()
            ->when($this->editingId, fn ($query) => $query->where('id', '!=', $this->editingId))
            ->pluck('station')
            ->all();

        return array_values(array_diff(BranchPrinter::STATIONS, $used));
    }

    public function edit(int $printerId): void
    {
        abort_unless($this->canSave, 403);

        $printer = $this->branch->printers()->findOrFail($printerId);

        $this->editingId = $printer->id;
        $this->station = $printer->station;
        $this->name = $printer->name ?? '';
        $this->ipAddress = $printer->ip_address;
        $this->port = (string) $printer->port;
        $this->paperWidth = (string) $printer->paper_width;
        $this->autoPrint = $printer->auto_print;
        $this->printFiscalNote = $printer->print_fiscal_note;
        $this->active = $printer->active;
        $this->testResult = null;
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->station = $this->availableStations[0] ?? 'geral';
        $this->name = '';
        $this->ipAddress = '';
        $this->port = '9100';
        $this->paperWidth = '80';
        $this->autoPrint = false;
        $this->printFiscalNote = false;
        $this->active = true;
        $this->testResult = null;
        $this->resetErrorBag();
    }

    public function testConnection(): void
    {
        abort_unless($this->canSave, 403);

        $this->validate($this->rules(), $this->messages());

        $printer = new BranchPrinter([
            'ip_address' => $this->ipAddress,
            'port' => (int) $this->port,
        ]);

        $this->testResultFor = $this->editingId;
        $this->testResult = app(PrinterServiceInterface::class)->testConnection($printer);
    }

    public function save(): void
    {
        abort_unless($this->canSave, 403);

        $this->validate($this->rules(), $this->messages());

        BranchPrinter::updateOrCreate(
            ['branch_id' => $this->branch->id, 'station' => $this->station],
            [
                'company_id' => $this->branch->company_id,
                'name' => $this->name !== '' ? $this->name : null,
                'ip_address' => $this->ipAddress,
                'port' => (int) $this->port,
                'paper_width' => (int) $this->paperWidth,
                'auto_print' => $this->autoPrint,
                'print_fiscal_note' => $this->printFiscalNote,
                'active' => $this->active,
            ]
        );

        session()->flash('status', 'Impressora salva.');
        $this->resetForm();
    }

    public function delete(int $printerId): void
    {
        abort_unless($this->canSave, 403);

        $this->branch->printers()->where('id', $printerId)->delete();

        if ($this->editingId === $printerId) {
            $this->resetForm();
        }

        session()->flash('status', 'Impressora removida.');
    }

    public function render()
    {
        return view('livewire.admin.branches.printer-settings')
            ->layout('layouts.app', ['title' => 'Configurar Impressoras — '.$this->branch->name]);
    }
}
