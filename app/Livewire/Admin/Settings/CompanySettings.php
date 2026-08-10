<?php

namespace App\Livewire\Admin\Settings;

use App\Rules\ReservedSlug;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class CompanySettings extends Component
{
    use WithFileUploads;

    public string $name = '';

    public string $slug = '';

    public ?string $tagline = '';

    public ?string $footer_text = '';

    public ?string $email = '';

    public string $primary_color = '#5c347f';

    public string $primary_color_dark = '#19273c';

    public string $primary_color_light = '#5c347f';

    public string $secondary_color = '#e36831';

    public string $secondary_color_light = '#D97706';

    public string $accent_color = '#cad1d8';

    public string $order_prefix = 'ORD';

    public bool $schedulingEnabled = false;

    public ?int $scheduleMinAdvanceMinutes = null;

    public bool $isFree = false;

    public bool $pixFeeAbsorbedByCompany = true;

    public bool $cardFeeAbsorbedByCompany = true;

    public bool $pdvManualDiscountEnabled = true;

    public array $chat_highlights = [];

    public ?string $facebook_pixel_id = '';

    public ?string $google_analytics_id = '';

    public ?string $google_ads_id = '';

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
            'name', 'slug', 'tagline', 'footer_text', 'email',
            'primary_color', 'primary_color_dark', 'primary_color_light',
            'secondary_color', 'secondary_color_light', 'accent_color',
            'order_prefix', 'facebook_pixel_id', 'google_analytics_id', 'google_ads_id'
        ));
        $this->chat_highlights = $company->chat_highlights ?? self::DEFAULT_HIGHLIGHTS;
        $this->schedulingEnabled = $company->schedulingEnabled();
        $this->scheduleMinAdvanceMinutes = $company->schedule_min_advance_minutes;
        $this->isFree = $company->isFree();
        $this->pixFeeAbsorbedByCompany = (bool) $company->pix_fee_absorbed_by_company;
        $this->cardFeeAbsorbedByCompany = (bool) $company->card_fee_absorbed_by_company;
        $this->pdvManualDiscountEnabled = (bool) $company->pdv_manual_discount_enabled;
    }

    protected function rules(): array
    {
        $company = app('current.company');

        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/', new ReservedSlug, "unique:companies,slug,{$company->id}"],
            'tagline' => ['nullable', 'string', 'max:255'],
            'footer_text' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'primary_color_dark' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'primary_color_light' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color_light' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'order_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'pixFeeAbsorbedByCompany' => ['boolean'],
            'cardFeeAbsorbedByCompany' => ['boolean'],
            'pdvManualDiscountEnabled' => ['boolean'],
            'schedulingEnabled' => ['boolean'],
            'scheduleMinAdvanceMinutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'chat_highlights' => ['array', 'max:6'],
            'chat_highlights.*.icon' => ['required', 'string', 'max:10'],
            'chat_highlights.*.title' => ['required', 'string', 'max:60'],
            'chat_highlights.*.description' => ['required', 'string', 'max:120'],
            'facebook_pixel_id' => ['nullable', 'string', 'regex:/^[0-9]{5,20}$/'],
            'google_analytics_id' => ['nullable', 'string', 'regex:/^G-[A-Z0-9]{4,12}$/'],
            'google_ads_id' => ['nullable', 'string', 'regex:/^AW-[0-9]{6,12}$/'],
        ];
    }

    public function save(): void
    {
        $this->facebook_pixel_id = trim((string) $this->facebook_pixel_id) ?: null;
        $this->google_analytics_id = trim((string) $this->google_analytics_id) ?: null;
        $this->google_ads_id = trim((string) $this->google_ads_id) ?: null;

        $validated = $this->validate($this->rules());
        $company = app('current.company');

        if ($company->isFree()) {
            $data = collect($validated)->only([
                'name', 'slug', 'tagline', 'footer_text', 'email', 'order_prefix',
                'facebook_pixel_id', 'google_analytics_id', 'google_ads_id',
            ])->toArray();
        } else {
            $data = collect($validated)->except(['logo', 'chat_highlights'])->toArray();
            $data['chat_highlights'] = $this->chat_highlights ?: null;
        }

        $data['slug'] = $company->slug;
        $data['pix_fee_absorbed_by_company'] = $this->pixFeeAbsorbedByCompany;
        $data['card_fee_absorbed_by_company'] = $this->cardFeeAbsorbedByCompany;
        $data['pdv_manual_discount_enabled'] = $this->pdvManualDiscountEnabled;
        $data['schedule_min_advance_minutes'] = $this->schedulingEnabled
            ? ($this->scheduleMinAdvanceMinutes ?: 60)
            : 0;

        if ($this->logo) {
            if ($company->logo_path) {
                Storage::disk('s3')->delete($company->logo_path);
            }
            $data['logo_path'] = $this->logo->storeAs('logos', "company_{$company->id}.".$this->logo->getClientOriginalExtension(), 's3');
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
