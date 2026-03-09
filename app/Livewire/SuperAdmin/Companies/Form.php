<?php

namespace App\Livewire\SuperAdmin\Companies;

use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use Livewire\Component;
use Livewire\WithFileUploads;

class Form extends Component
{
    use WithFileUploads;

    public ?Company $company = null;
    public bool $isEditing   = false;

    public string $name                     = '';
    public string $slug                     = '';
    public string $subdomain                = '';
    public string $tagline                  = '';
    public string $footer_text              = '';
    public string $primary_color            = '#B91C1C';
    public string $primary_color_dark       = '#7F1D1D';
    public string $primary_color_light      = '#DC2626';
    public string $secondary_color          = '#B45309';
    public string $secondary_color_light    = '#D97706';
    public string $accent_color             = '#FEF3C7';
    public string $order_prefix             = 'ORD';
    public string $abacatepay_token         = '';
    public string $abacatepay_webhook_secret = '';
    public bool   $active                   = true;

    public $logo = null;

    protected function rules(): array
    {
        $ignoreId = $this->company?->id ?? 'NULL';
        return [
            'name'                     => ['required', 'string', 'max:100'],
            'slug'                     => ['required', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/', "unique:companies,slug,{$ignoreId}"],
            'subdomain'                => ['nullable', 'string', 'max:63', 'regex:/^[a-z0-9\-]*$/', "unique:companies,subdomain,{$ignoreId}"],
            'tagline'                  => ['nullable', 'string', 'max:255'],
            'footer_text'              => ['nullable', 'string', 'max:255'],
            'primary_color'            => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'primary_color_dark'       => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'primary_color_light'      => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color'          => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color_light'    => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color'             => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'order_prefix'             => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'abacatepay_token'         => ['nullable', 'string', 'max:500'],
            'abacatepay_webhook_secret'=> ['nullable', 'string', 'max:500'],
            'active'                   => ['boolean'],
            'logo'                     => ['nullable', 'image', 'max:2048'],
        ];
    }

    public function mount(?Company $company = null): void
    {
        if ($company?->exists) {
            $this->company   = $company;
            $this->isEditing = true;
            $this->fill($company->only(
                'name', 'slug', 'subdomain', 'tagline', 'footer_text',
                'primary_color', 'primary_color_dark', 'primary_color_light',
                'secondary_color', 'secondary_color_light', 'accent_color',
                'order_prefix', 'active'
            ));
            $this->abacatepay_token          = $company->abacatepay_token ?? '';
            $this->abacatepay_webhook_secret = $company->abacatepay_webhook_secret ?? '';
        }
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules());

        $data = collect($validated)->except(['logo'])->toArray();
        $data['abacatepay_token']          = $this->abacatepay_token ?: null;
        $data['abacatepay_webhook_secret'] = $this->abacatepay_webhook_secret ?: null;

        if ($this->logo) {
            $data['logo_path'] = $this->logo->store('logos', 's3');
        }

        if ($this->isEditing) {
            $this->company->update($data);
            session()->flash('status', 'Empresa atualizada.');
        } else {
            Company::create($data);
            session()->flash('status', 'Empresa criada.');
        }

        $this->redirect(route('superadmin.companies.index'));
    }

    public function render()
    {
        return view('livewire.super-admin.companies.form')
            ->layout('layouts.app', ['title' => $this->isEditing ? 'Editar Empresa' : 'Nova Empresa']);
    }
}
