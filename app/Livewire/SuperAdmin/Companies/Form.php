<?php

namespace App\Livewire\SuperAdmin\Companies;

use App\Mail\WelcomeUser;
use App\Models\Company;
use App\Models\Role;
use App\Models\Scopes\CompanyScope;
use App\Models\User;
use App\Models\UserPermission;
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
            'abacatepay_token'         => ['nullable', 'string', 'max:500'],
            'abacatepay_webhook_secret'=> ['nullable', 'string', 'max:500'],
            'active'                   => ['boolean'],
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
            $this->abacatepay_token          = $company->abacatepay_token ?? '';
            $this->abacatepay_webhook_secret = $company->abacatepay_webhook_secret ?? '';
        }
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules());

        $companyData = collect($validated)
            ->except(['logo', 'manager_name', 'manager_email', 'manager_password', 'manager_password_confirmation'])
            ->toArray();
        $companyData['subdomain'] = $this->subdomain ?: null;
        $companyData['abacatepay_token']          = $this->abacatepay_token ?: null;
        $companyData['abacatepay_webhook_secret'] = $this->abacatepay_webhook_secret ?: null;

        if ($this->logo) {
            $companyData['logo_path'] = $this->logo->store('logos', 's3');
        }

        if ($this->isEditing) {
            $this->company->update($companyData);
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

                $adminRole = Role::where('slug', 'company_admin')
                    ->whereNull('company_id')
                    ->with('permissions')
                    ->first();

                if ($adminRole) {
                    $adminRole->permissions->each(function ($permission) use ($user, $company) {
                        UserPermission::updateOrCreate(
                            [
                                'user_id'       => $user->id,
                                'company_id'    => $company->id,
                                'permission_id' => $permission->id,
                            ],
                            ['granted' => true]
                        );
                    });
                }

                Mail::to($user)->queue(new WelcomeUser($user, $company, $temporaryPassword));
            }

            session()->flash('status', 'Empresa criada.' . ($this->create_manager ? ' Gerente cadastrado e vinculado.' : ''));
        }

        Cache::forget('companies:active');

        $this->redirect(route('superadmin.companies.index'));
    }

    public function render()
    {
        return view('livewire.super-admin.companies.form')
            ->layout('layouts.app', ['title' => $this->isEditing ? 'Editar Empresa' : 'Nova Empresa']);
    }
}
