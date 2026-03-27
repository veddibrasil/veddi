<?php

namespace App\Livewire\SuperAdmin\Companies;

use App\Enums\Plan;
use App\Jobs\CreateAsaasSubscription;
use App\Mail\WelcomeUser;
use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AsaasService;
use App\Services\UserPermissionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class Form extends Component
{
    use WithFileUploads;

    public ?Company $company = null;
    public bool $isEditing   = false;

    public string $name                     = '';
    public string $slug                     = '';
    public ?string $subdomain               = '';
    public ?string $tagline                 = '';
    public ?string $footer_text             = '';
    public string $primary_color         = '#5c347f';
    public string $primary_color_dark    = '#19273c';
    public string $primary_color_light   = '#5c347f';
    public string $secondary_color       = '#e36831';
    public string $secondary_color_light = '#D97706';
    public string $accent_color          = '#cad1d8';
    public string $order_prefix             = 'ORD';
    public bool   $active                   = true;
    public string $plan                     = 'free';
    public string $status                   = 'ACTIVE';

    public $logo = null;

    // Controle de alteração de plano
    public string $originalPlan    = 'free';
    public bool   $bypassPayment   = false;

    // Gerente da empresa (apenas na criação)
    public bool   $create_manager = false;
    public string $manager_name   = '';
    public string $manager_email  = '';

    protected function rules(): array
    {
        $ignoreId = $this->company?->id ?? 'NULL';
        $rules = [
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
            'order_prefix'             => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/', "unique:companies,order_prefix,{$ignoreId}"],
            'active'                   => ['boolean'],
            'plan'                     => ['required', 'in:free,essencial,pro'],
            'status'                   => ['required', 'in:ACTIVE,PENDING_PAYMENT,BLOCKED'],
            'logo'                     => ['nullable', 'image', 'max:2048'],
        ];

        if (! $this->isEditing && $this->create_manager) {
            $rules['manager_name']  = ['required', 'string', 'max:255'];
            $rules['manager_email'] = ['required', 'email', 'max:255', 'unique:users,email'];
        }

        return $rules;
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
            $this->plan                      = $company->plan?->value ?? 'free';
            $this->originalPlan              = $this->plan;
            $this->status                    = $company->status ?? 'ACTIVE';
        }
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules());

        $companyData = collect($validated)
            ->except(['logo', 'manager_name', 'manager_email', 'manager_password', 'manager_password_confirmation'])
            ->toArray();
        $companyData['subdomain'] = $this->subdomain ?: null;

        if ($this->logo) {
            $companyData['logo_path'] = $this->logo->store('logos', 's3');
        }

        if ($this->isEditing) {
            $planChanged = $this->plan !== $this->originalPlan;

            if ($planChanged) {
                $this->handlePlanChange($companyData);
            } else {
                $companyData['active'] = $this->status === 'ACTIVE';
                $this->company->update($companyData);
            }

            session()->flash('status', 'Empresa atualizada.');
        } else {
            $company = Company::create($companyData);

            if ($this->create_manager) {
                $temporaryPassword = Str::password(12, symbols: false);

                $user = User::create([
                    'name'     => $this->manager_name,
                    'email'    => $this->manager_email,
                    'password' => Hash::make($temporaryPassword),
                ]);

                $user->companies()->attach($company->id, [
                    'role'      => 'company_admin',
                    'branch_id' => null,
                ]);

                UserPermissionService::assignRolePermissions($user, $company, 'company_admin');

                Mail::to($user)->queue(new WelcomeUser($user, $company, $temporaryPassword));
            }

            session()->flash('status', 'Empresa criada.' . ($this->create_manager ? ' Gerente cadastrado e vinculado.' : ''));
        }

        Cache::forget('companies:active');

        $this->redirect(route('superadmin.companies.index'));
    }

    private function handlePlanChange(array $baseData): void
    {
        $asaasService = app(AsaasService::class);
        $company      = $this->company;
        $targetPlan   = Plan::tryFrom($this->plan);

        // Cancel existing subscription if any
        if ($company->asaas_subscription_id) {
            try {
                $asaasService->cancelSubscription($company->asaas_subscription_id);
            } catch (\Throwable) {
                // Log already handled inside AsaasService; proceed anyway
            }

            Subscription::where('company_id', $company->id)
                ->where('asaas_subscription_id', $company->asaas_subscription_id)
                ->whereIn('status', ['active', 'pending'])
                ->update(['status' => 'cancelled']);
        }

        if ($targetPlan === Plan::Free) {
            // Downgrade to free: always ACTIVE (setup fee already paid)
            $baseData['active']                = true;
            $baseData['status']                = 'ACTIVE';
            $baseData['asaas_subscription_id'] = null;
            $company->update($baseData);
        } else {
            // Upgrade/crossgrade to paid plan
            if ($this->bypassPayment) {
                // SuperAdmin override: activate immediately, no Asaas subscription
                $baseData['active']                = true;
                $baseData['status']                = 'ACTIVE';
                $baseData['asaas_subscription_id'] = null;
                $company->update($baseData);
            } else {
                // Normal flow: await payment via Asaas
                $baseData['active']                = false;
                $baseData['status']                = 'PENDING_PAYMENT';
                $baseData['asaas_subscription_id'] = null;
                $company->update($baseData);

                if ($company->asaas_customer_id) {
                    CreateAsaasSubscription::dispatch($company->fresh());
                }
            }
        }
    }

    public function render()
    {
        return view('livewire.super-admin.companies.form')
            ->layout('layouts.app', ['title' => $this->isEditing ? 'Editar Empresa' : 'Nova Empresa']);
    }
}
