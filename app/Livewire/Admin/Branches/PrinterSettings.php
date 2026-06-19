<?php

namespace App\Livewire\Admin\Branches;

use App\Contracts\PrinterServiceInterface;
use App\Models\Branch;
use App\Models\BranchPrinter;
use Livewire\Component;

class PrinterSettings extends Component
{
    public Branch $branch;

    public bool $canSave = false;

    public string $name = '';

    public string $ipAddress = '';

    public string $port = '9100';

    public string $paperWidth = '80';

    public bool $autoPrint = false;

    public bool $active = true;

    public ?bool $testResult = null;

    protected function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:100'],
            'ipAddress' => ['required', 'ip'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'paperWidth' => ['required', 'in:58,80'],
            'autoPrint' => ['boolean'],
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

            if ($user->isBranchManager($company) && $user->branchIdForCompany($company) !== $branch->id) {
                abort(403);
            }
        }

        $printer = $branch->printer;

        if (! $printer) {
            return;
        }

        $this->name = $printer->name ?? '';
        $this->ipAddress = $printer->ip_address;
        $this->port = (string) $printer->port;
        $this->paperWidth = (string) $printer->paper_width;
        $this->autoPrint = $printer->auto_print;
        $this->active = $printer->active;
    }

    public function testConnection(): void
    {
        abort_unless($this->canSave, 403);

        $this->validate($this->rules(), $this->messages());

        $printer = new BranchPrinter([
            'ip_address' => $this->ipAddress,
            'port' => (int) $this->port,
        ]);

        $this->testResult = app(PrinterServiceInterface::class)->testConnection($printer);
    }

    public function save(): void
    {
        abort_unless($this->canSave, 403);

        $this->validate($this->rules(), $this->messages());

        BranchPrinter::updateOrCreate(
            ['branch_id' => $this->branch->id],
            [
                'company_id' => $this->branch->company_id,
                'name' => $this->name !== '' ? $this->name : null,
                'ip_address' => $this->ipAddress,
                'port' => (int) $this->port,
                'paper_width' => (int) $this->paperWidth,
                'auto_print' => $this->autoPrint,
                'active' => $this->active,
            ]
        );

        session()->flash('status', 'Configurações da impressora salvas.');
        $this->redirect(route('admin.branches.printer', $this->branch));
    }

    public function render()
    {
        return view('livewire.admin.branches.printer-settings')
            ->layout('layouts.app', ['title' => 'Configurar Impressora — '.$this->branch->name]);
    }
}
