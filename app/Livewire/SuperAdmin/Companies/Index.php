<?php

namespace App\Livewire\SuperAdmin\Companies;

use App\Enums\Plan;
use App\Livewire\SuperAdmin\Concerns\RequiresSuperAdmin;
use App\Models\Company;
use App\Models\Order;
use App\Models\Scopes\CompanyScope;
use App\Services\Company\AddonModulePricing;
use App\Services\SuperAdmin\AuditLog;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use RequiresSuperAdmin;
    use WithPagination;

    public string $search = '';

    public string $planFilter = '';

    public string $missingModule = '';

    public ?int $deletingId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPlanFilter(): void
    {
        $this->resetPage();
    }

    public function updatedMissingModule(): void
    {
        $this->resetPage();
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
    }

    public function toggleActive(int $companyId): void
    {
        $company = Company::withoutGlobalScope(CompanyScope::class)->findOrFail($companyId);
        $company->update(['active' => ! $company->active]);
    }

    public function delete(): void
    {
        $company = Company::withoutGlobalScope(CompanyScope::class)
            ->with('balance')
            ->findOrFail($this->deletingId);

        // Hard-delete em cascata (~27 tabelas com cascadeOnDelete: pedidos, pagamentos,
        // carteira, notas fiscais). Sem soft-delete no model, então bloqueia aqui quando
        // ainda há obrigação financeira/fiscal em aberto — evita apagar histórico exigido
        // por retenção legal ou saldo de restaurante ainda não sacado.
        $balance = (float) ($company->balance->available_balance ?? 0) + (float) ($company->balance->blocked_balance ?? 0);

        if ($balance > 0.0) {
            $this->deletingId = null;
            session()->flash('error', 'Empresa possui saldo em carteira. Faça o saque/estorno antes de excluir.');

            return;
        }

        if ($company->orders()->whereHas('payment')->exists()) {
            $this->deletingId = null;
            session()->flash('error', 'Empresa possui pedidos pagos no histórico. Exclusão bloqueada para preservar o histórico financeiro.');

            return;
        }

        if ($company->fiscalNotes()->exists()) {
            $this->deletingId = null;
            session()->flash('error', 'Empresa possui notas fiscais emitidas. Exclusão bloqueada por obrigação de retenção fiscal.');

            return;
        }

        AuditLog::companyDeleted(auth()->user(), $company);

        $company->delete();
        $this->deletingId = null;
        session()->flash('status', 'Empresa excluída.');
    }

    public function render()
    {
        $moduleColumn = AddonModulePricing::MODULES[$this->missingModule]['column'] ?? null;
        $companies = Company::withoutGlobalScope(CompanyScope::class)
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when(in_array($this->planFilter, Plan::values(), true), fn ($q) => $q->where('plan', $this->planFilter))
            ->when($moduleColumn, fn ($q) => $q->where(fn ($modules) => $modules->where($moduleColumn, false)->orWhereNull($moduleColumn)))
            ->withCount([
                'branches' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class),
                'orders' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class),
                'orders as confirmed_orders_this_month' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class)
                    ->whereIn('status', Order::CONFIRMED_STATUSES)
                    ->whereBetween('created_at', [now()->startOfMonth(), now()]),
            ])
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.super-admin.companies.index', compact('companies'))
            ->layout('layouts.app', ['title' => 'Super Admin — Empresas']);
    }
}
