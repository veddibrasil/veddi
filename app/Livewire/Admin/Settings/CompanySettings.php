<?php

namespace App\Livewire\Admin\Settings;

use Livewire\Component;
use Livewire\WithFileUploads;

class CompanySettings extends Component
{
    use WithFileUploads;

    public string $name                  = '';
    public string $slug                  = '';
    public string $tagline               = '';
    public string $footer_text           = '';
    public string $primary_color         = '#B91C1C';
    public string $primary_color_dark    = '#7F1D1D';
    public string $primary_color_light   = '#DC2626';
    public string $secondary_color       = '#B45309';
    public string $secondary_color_light = '#D97706';
    public string $accent_color          = '#FEF3C7';
    public string $order_prefix          = 'ORD';
    public string $abacatepay_token      = '';

    public $logo = null;

    public function mount(): void
    {
        $company = app('current.company');
        $this->fill($company->only(
            'name', 'slug', 'tagline', 'footer_text',
            'primary_color', 'primary_color_dark', 'primary_color_light',
            'secondary_color', 'secondary_color_light', 'accent_color',
            'order_prefix'
        ));
        $this->abacatepay_token = $company->abacatepay_token ?? '';
    }

    protected function rules(): array
    {
        $company = app('current.company');
        return [
            'name'                  => ['required', 'string', 'max:100'],
            'slug'                  => ['required', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/', "unique:companies,slug,{$company->id}"],
            'tagline'               => ['nullable', 'string', 'max:255'],
            'footer_text'           => ['nullable', 'string', 'max:255'],
            'primary_color'         => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'primary_color_dark'    => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'primary_color_light'   => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color'       => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color_light' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color'          => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'order_prefix'          => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'abacatepay_token'      => ['nullable', 'string', 'max:500'],
            'logo'                  => ['nullable', 'image', 'max:2048'],
        ];
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules());
        $company   = app('current.company');

        $data = collect($validated)->except(['logo', 'abacatepay_token'])->toArray();
        $data['abacatepay_token'] = $this->abacatepay_token ?: null;

        if ($this->logo) {
            $data['logo_path'] = $this->logo->storeAs('logos', "company_{$company->id}." . $this->logo->getClientOriginalExtension(), 's3');
        }

        $company->update($data);

        session()->flash('status', 'Configurações salvas com sucesso.');
        $this->redirect(route('admin.settings'));
    }

    public function render()
    {
        return view('livewire.admin.settings.company-settings')
            ->layout('layouts.app', ['title' => 'Configurações da Empresa']);
    }
}
