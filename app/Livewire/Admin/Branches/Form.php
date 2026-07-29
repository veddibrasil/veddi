<?php

namespace App\Livewire\Admin\Branches;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Form extends Component
{
    public ?Branch $branch = null;

    public bool $isEditing = false;

    public string $name = '';

    public string $address = '';

    public string $number = '';

    public string $complement = '';

    public string $neighborhood = '';

    public string $city = '';

    public string $state = '';

    public string $cep = '';

    public string $phone = '';

    public bool $active = true;

    public array $available_days = [0, 1, 2, 3, 4, 5, 6];

    public array $day_hours = [
        0 => ['opens_at' => '08:00', 'closes_at' => '20:00'],
        1 => ['opens_at' => '08:00', 'closes_at' => '20:00'],
        2 => ['opens_at' => '08:00', 'closes_at' => '20:00'],
        3 => ['opens_at' => '08:00', 'closes_at' => '20:00'],
        4 => ['opens_at' => '08:00', 'closes_at' => '20:00'],
        5 => ['opens_at' => '08:00', 'closes_at' => '20:00'],
        6 => ['opens_at' => '08:00', 'closes_at' => '20:00'],
    ];

    public bool $needsCompanySelect = false;

    public bool $canSave = false;

    public ?int $company_id = null;

    protected function rules(): array
    {
        $companyRule = $this->needsCompanySelect
            ? ['required', 'integer', 'exists:companies,id']
            : ['nullable'];

        return [
            'company_id' => $companyRule,
            'name' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:100'],
            'neighborhood' => ['nullable', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'size:2'],
            'cep' => ['nullable', 'regex:/^\\d{5}-?\\d{3}$/'],
            'phone' => ['nullable', 'regex:/^\(?\d{2}\)?[\s\-]?\d{4,5}[\-]?\d{4}$/'],
            'active' => ['boolean'],
            'available_days' => ['required', 'array', 'min:1'],
            'available_days.*' => ['integer', 'between:0,6'],
            'day_hours' => ['required', 'array'],
        ] + $this->dayHoursRules();
    }

    protected function dayHoursRules(): array
    {
        $rules = [];

        foreach ($this->available_days as $day) {
            $rules["day_hours.{$day}.opens_at"] = ['required', 'date_format:H:i'];
            $rules["day_hours.{$day}.closes_at"] = ['required', 'date_format:H:i'];
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'company_id.required' => 'Selecione uma empresa.',
            'company_id.exists' => 'Empresa inválida.',
            'name.required' => 'Informe o nome da filial.',
            'address.required' => 'Informe o endereço.',
            'city.required' => 'Informe a cidade.',
            'state.size' => 'UF inválida. Use 2 letras (ex: SP).',
            'cep.regex' => 'CEP inválido. Use o formato 00000-000.',
            'phone.regex' => 'Telefone inválido.',
            'available_days.required' => 'Selecione ao menos um dia de funcionamento.',
            'available_days.min' => 'Selecione ao menos um dia de funcionamento.',
            'day_hours.*.opens_at.required' => 'Informe o horário de abertura.',
            'day_hours.*.closes_at.required' => 'Informe o horário de fechamento.',
        ];
    }

    public function mount(?Branch $branch = null): void
    {
        $this->resolvePermissions($branch);

        if ($branch?->exists) {
            $this->fillFromBranch($branch);
        }
    }

    public function save(): void
    {
        abort_unless($this->canSave, 403);

        $validated = $this->validate($this->rules(), $this->messages());
        $validated['available_days'] = array_map('intval', $validated['available_days']);

        $businessHours = $this->buildBusinessHours($validated['available_days']);
        $validated['business_hours'] = $businessHours;

        $opens = array_column(array_values($businessHours), 'opens_at');
        $closes = array_column(array_values($businessHours), 'closes_at');
        $validated['opens_at'] = min($opens);
        $validated['closes_at'] = max($closes);
        unset($validated['day_hours']);

        $branch = $this->persistBranch($validated);
        $companyId = $branch->company_id;

        session()->flash('status', $this->isEditing ? 'Filial atualizada.' : 'Filial criada.');
        session()->forget('chat_state');

        Cache::forget("branches:company:{$companyId}");
        Cache::forget("open_branches:company:{$companyId}");

        $this->redirect(route('admin.branches.index'));
    }

    public function render()
    {
        $companies = $this->needsCompanySelect
            ? Company::withoutGlobalScope(CompanyScope::class)
                ->where('active', true)
                ->orderBy('name')
                ->get()
            : collect();

        return view('livewire.admin.branches.form', compact('companies'))
            ->layout('layouts.app', ['title' => $this->isEditing ? 'Editar Filial' : 'Nova Filial']);
    }

    private function resolvePermissions(?Branch $branch): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            $this->canSave = true;
            $this->needsCompanySelect = true;
            $this->company_id = Company::withoutGlobalScope(CompanyScope::class)
                ->where('active', true)
                ->orderBy('name')
                ->value('id');

            return;
        }

        $company = $user->companies()->first();
        $this->company_id = $company?->id;

        if (! $company) {
            return;
        }

        $isEditing = $branch?->exists ?? false;
        $permission = $isEditing ? 'branches.update' : 'branches.create';
        $this->canSave = $user->hasPermission($permission, $company);
    }

    private function fillFromBranch(Branch $branch): void
    {
        $this->branch = $branch;
        $this->isEditing = true;
        $this->company_id = $branch->company_id;

        $branch->loadMissing('addressRecord');

        $this->name = (string) ($branch->name ?? '');
        $this->address = (string) ($branch->address ?? '');
        $this->number = (string) ($branch->number ?? '');
        $this->complement = (string) ($branch->complement ?? '');
        $this->neighborhood = (string) ($branch->neighborhood ?? '');
        $this->city = (string) ($branch->city ?? '');
        $this->state = (string) ($branch->state ?? '');
        $this->cep = (string) ($branch->cep ?? '');
        $this->phone = (string) ($branch->phone ?? '');
        $this->active = (bool) ($branch->active ?? true);
        $this->available_days = $branch->available_days ?? [0, 1, 2, 3, 4, 5, 6];

        $this->loadBusinessHours($branch);
    }

    private function loadBusinessHours(Branch $branch): void
    {
        $globalOpens = (string) ($branch->opens_at ?? '08:00');
        $globalCloses = (string) ($branch->closes_at ?? '20:00');
        $stored = $branch->business_hours ?? [];

        foreach (range(0, 6) as $day) {
            $perDay = $stored[$day] ?? $stored[(string) $day] ?? null;
            $this->day_hours[$day] = [
                'opens_at' => $perDay['opens_at'] ?? $globalOpens,
                'closes_at' => $perDay['closes_at'] ?? $globalCloses,
            ];
        }
    }

    private function buildBusinessHours(array $availableDays): array
    {
        $hours = [];

        foreach ($availableDays as $day) {
            $hours[$day] = [
                'opens_at' => $this->day_hours[$day]['opens_at'],
                'closes_at' => $this->day_hours[$day]['closes_at'],
            ];
        }

        return $hours;
    }

    private function persistBranch(array $validated): Branch
    {
        return $this->isEditing
            ? $this->updateBranch($validated)
            : $this->createBranch($validated);
    }

    private function updateBranch(array $validated): Branch
    {
        $data = collect($validated)->except('company_id')->toArray();

        if ($this->needsCompanySelect && $this->company_id) {
            $data['company_id'] = $this->company_id;
        }

        $branch = Branch::withoutGlobalScope(CompanyScope::class)->findOrFail($this->branch->id);
        $branch->fill($data)->save();

        return $branch;
    }

    private function createBranch(array $validated): Branch
    {
        $data = collect($validated)->except('company_id')->toArray();

        if ($this->needsCompanySelect) {
            $data['company_id'] = $this->company_id;

            return Branch::withoutGlobalScope(CompanyScope::class)->create($data);
        }

        return Branch::create($data);
    }
}
