<?php

namespace App\Livewire\Admin\Branches;

use App\Models\Branch;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Form extends Component
{
    public ?Branch $branch = null;
    public bool $isEditing  = false;

    public string $name      = '';
    public string $address   = '';
    public string $city      = '';
    public string $phone     = '';
    public bool   $active    = true;
    public string $opens_at  = '08:00';
    public string $closes_at = '20:00';

    protected function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:100'],
            'address'   => ['required', 'string', 'max:255'],
            'city'      => ['required', 'string', 'max:100'],
            'phone'     => ['nullable', 'regex:/^\(?\d{2}\)?[\s\-]?\d{4,5}[\-]?\d{4}$/'],
            'active'    => ['boolean'],
            'opens_at'  => ['required', 'date_format:H:i'],
            'closes_at' => ['required', 'date_format:H:i'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required'      => 'Informe o nome da filial.',
            'address.required'   => 'Informe o endereço.',
            'city.required'      => 'Informe a cidade.',
            'phone.regex'        => 'Telefone inválido.',
            'opens_at.required'  => 'Informe o horário de abertura.',
            'closes_at.required' => 'Informe o horário de fechamento.',
        ];
    }

    public function mount(?Branch $branch = null): void
    {
        if ($branch?->exists) {
            $this->branch    = $branch;
            $this->isEditing = true;
            $this->fill($branch->only('name', 'address', 'city', 'phone', 'active', 'opens_at', 'closes_at'));
        }
    }

    public function save(): void
    {
        $validated = $this->validate($this->rules(), $this->messages());

        if ($this->isEditing) {
            $this->branch->update($validated);
            session()->flash('status', 'Filial atualizada.');
        } else {
            Branch::create($validated);
            session()->flash('status', 'Filial criada.');
        }

        // Invalida cache de branches e horários para a empresa atual
        $companyId = app()->bound('current.company') ? app('current.company')->id : null;
        Cache::forget("branches:company:{$companyId}");
        Cache::forget("open_branches:company:{$companyId}");

        $this->redirect(route('admin.branches.index'));
    }

    public function render()
    {
        return view('livewire.admin.branches.form')
            ->layout('layouts.app', ['title' => $this->isEditing ? 'Editar Filial' : 'Nova Filial']);
    }
}
