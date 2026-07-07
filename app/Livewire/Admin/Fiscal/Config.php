<?php

namespace App\Livewire\Admin\Fiscal;

use App\Models\CompanyFiscalConfig;
use Illuminate\Support\Facades\Storage;
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

    public bool $hasProviderToken = false;

    public string $environment = 'homologacao';

    public string $nfceSerie = '1';

    public $certificateFile = null;

    public bool $hasCertificate = false;

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
            $this->inscricaoEstadual = $config->inscricao_estadual ?? '';
            $this->provider = $config->provider;
            $this->environment = $config->environment;
            $this->nfceSerie = (string) $config->nfce_serie;
            $this->hasProviderToken = filled($config->provider_token);
            $this->hasCertificate = filled($config->certificate_path);
        }
    }

    public function save(): void
    {
        $company = app('current.company');
        abort_unless($this->canManage, 403);

        $this->validate([
            'crt' => ['required', 'integer', 'in:1,2,3'],
            'providerToken' => ['nullable', 'string', 'max:255'],
            'environment' => ['required', 'in:homologacao,producao'],
            'nfceSerie' => ['required', 'integer', 'min:1'],
            'inscricaoEstadual' => ['nullable', 'string', 'max:50'],
            'certificateFile' => ['nullable', 'file', 'mimes:pfx,p12', 'max:2048'],
            'certificatePassword' => ['nullable', 'string', 'max:255'],
        ]);

        $config = $company->fiscalConfig ?? new CompanyFiscalConfig(['company_id' => $company->id]);

        $config->crt = $this->crt;
        $config->enabled = $this->enabled;
        $config->inscricao_estadual = $this->inscricaoEstadual ?: null;
        $config->provider = $this->provider;
        $config->environment = $this->environment;
        $config->nfce_serie = (int) $this->nfceSerie;

        // Token/senha só são sobrescritos quando o usuário digita um novo valor —
        // o campo fica em branco no mount() para não expor o segredo já salvo.
        if ($this->providerToken !== '') {
            $config->provider_token = $this->providerToken;
        }

        if ($this->certificateFile) {
            if ($config->certificate_path) {
                Storage::disk('local')->delete($config->certificate_path);
            }

            $config->certificate_path = $this->certificateFile->storeAs(
                'fiscal-certificates',
                "company-{$company->id}.".$this->certificateFile->getClientOriginalExtension(),
                'local',
            );
        }

        if ($this->certificatePassword !== '') {
            $config->certificate_password = $this->certificatePassword;
        }

        $config->save();

        $this->certificateFile = null;
        $this->certificatePassword = '';
        $this->providerToken = '';
        $this->hasProviderToken = filled($config->provider_token);
        $this->hasCertificate = filled($config->certificate_path);

        session()->flash('status', 'Configurações fiscais salvas.');
    }

    public function render()
    {
        return view('livewire.admin.fiscal.config')
            ->layout('layouts.app', ['title' => 'Configurações Fiscais']);
    }
}
