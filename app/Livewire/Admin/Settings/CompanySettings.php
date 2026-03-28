<?php

namespace App\Livewire\Admin\Settings;

use Livewire\Component;
use Livewire\WithFileUploads;

class CompanySettings extends Component
{
    use WithFileUploads;

    public string $name                  = '';
    public string $slug                  = '';
    public ?string $tagline               = '';
    public ?string $footer_text          = '';
    public string $primary_color         = '#5c347f';
    public string $primary_color_dark    = '#19273c';
    public string $primary_color_light   = '#5c347f';
    public string $secondary_color       = '#e36831';
    public string $secondary_color_light = '#D97706';
    public string $accent_color          = '#cad1d8';
    public string $order_prefix          = 'ORD';

    public bool $isFree = false;

    public array $chat_highlights = [];

    public const DEFAULT_HIGHLIGHTS = [
        ['icon' => '🥟', 'title' => 'Salgados fresquinhos', 'description' => 'Feitos na hora, com ingredientes selecionados'],
        ['icon' => '⚡', 'title' => 'Pedido rápido',        'description' => 'Faça seu pedido em poucos cliques pelo chat'],
        ['icon' => '💳', 'title' => 'Pague por PIX',        'description' => 'Confirmação automática e entrega ágil'],
    ];

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
        $this->chat_highlights  = $company->chat_highlights ?? self::DEFAULT_HIGHLIGHTS;
        $this->isFree           = $company->isFree();
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
            'logo'                         => ['nullable', 'image', 'max:2048'],
            'chat_highlights'              => ['array', 'max:6'],
            'chat_highlights.*.icon'       => ['required', 'string', 'max:10'],
            'chat_highlights.*.title'      => ['required', 'string', 'max:60'],
            'chat_highlights.*.description'=> ['required', 'string', 'max:120'],
        ];
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules());
        $company   = app('current.company');

        if ($company->isFree()) {
            $data = collect($validated)->only(['name', 'slug', 'tagline', 'footer_text', 'order_prefix'])->toArray();
        } else {
            $data = collect($validated)->except(['logo', 'chat_highlights'])->toArray();
            $data['chat_highlights'] = $this->chat_highlights ?: null;
        }

        if ($this->logo) {
            $data['logo_path'] = $this->logo->storeAs('logos', "company_{$company->id}." . $this->logo->getClientOriginalExtension(), 's3');
        }

        $company->update($data);

        session()->flash('status', 'Configurações salvas com sucesso.');
        $this->redirect(route('admin.settings'));
    }

    public function addHighlight(): void
    {
        if (count($this->chat_highlights) < 6) {
            $this->chat_highlights[] = ['icon' => '✨', 'title' => '', 'description' => ''];
        }
    }

    public function removeHighlight(int $index): void
    {
        array_splice($this->chat_highlights, $index, 1);
        $this->chat_highlights = array_values($this->chat_highlights);
    }

    public function render()
    {
        return view('livewire.admin.settings.company-settings', [
            'currentCompany' => app('current.company'),
        ])->layout('layouts.app', ['title' => 'Configurações da Empresa']);
    }
}
