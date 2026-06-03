<?php

namespace App\Livewire\Admin\Fiscal;

use App\Models\CompanyFiscalConfig;
use Livewire\Component;
use Livewire\WithFileUploads;

class Config extends Component
{
    use WithFileUploads;

    public int $crt = 1;

    public bool $enabled = false;

    public string $inscricaoEstadual = '';

    public string $provider = 'focus_nfe';

    public string $providerToken = '';

    public string $environment = 'homologacao';

    public string $nfceSerie = '1';

    public $certificateFile = null;

    public string $certificatePassword = '';

    public bool $canManage = false;

    public function mount(): void
    {
        $company = app('current.company');
        $user = auth()->user();

        $this->canManage = $user->hasPermission('fiscal.settings', $company);

        $config = $company->fiscalConfig;

        if ($config) {
            $this->crt = $config->crt;
            $this->enabled = $config->enabled;
            $this->inscricaoEstadual = $config->getSetting('inscricao_estadual') ?? '';
            $this->provider = $config->getSetting('provider') ?? 'focus_nfe';
            $this->providerToken = $config->getSetting('provider_token') ?? '';
            $this->environment = $config->getSetting('environment') ?? 'homologacao';
            $this->nfceSerie = (string) ($config->getSetting('nfce_serie') ?? '1');
        }
    }

    public function save(): void
    {
        $company = app('current.company');
        abort_unless($this->canManage, 403);

        $this->validate([
            'crt' => ['required', 'integer', 'in:1,2,3'],
            'providerToken' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'in:homologacao,producao'],
            'nfceSerie' => ['required', 'integer', 'min:1'],
            'inscricaoEstadual' => ['nullable', 'string', 'max:50'],
            'certificateFile' => ['nullable', 'file', 'mimes:pfx,p12', 'max:2048'],
            'certificatePassword' => ['nullable', 'string', 'max:255'],
        ]);

        $config = $company->fiscalConfig ?? new CompanyFiscalConfig(['company_id' => $company->id]);

        $config->crt = $this->crt;
        $config->enabled = $this->enabled;

        $config->setSetting('inscricao_estadual', $this->inscricaoEstadual ?: null);
        $config->setSetting('provider', $this->provider);
        $config->setSetting('provider_token', $this->providerToken);
        $config->setSetting('environment', $this->environment);
        $config->setSetting('nfce_serie', (int) $this->nfceSerie);

        if ($this->certificateFile) {
            $config->setSetting('certificate', base64_encode(file_get_contents($this->certificateFile->getRealPath())));
        }

        if ($this->certificatePassword !== '') {
            $config->setSetting('certificate_password', $this->certificatePassword);
        }

        $config->save();

        $this->certificateFile = null;
        $this->certificatePassword = '';

        session()->flash('status', 'Configurações fiscais salvas.');
    }

    public function render()
    {
        return view('livewire.admin.fiscal.config')
            ->layout('layouts.app', ['title' => 'Configurações Fiscais']);
    }
}
