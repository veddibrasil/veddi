<?php

namespace App\Livewire\SuperAdmin\Integrations;

use App\Exceptions\Food99ApiException;
use App\Livewire\SuperAdmin\Concerns\RequiresSuperAdmin;
use App\Models\Food99Integration;
use App\Models\IfoodIntegration;
use App\Services\Food99\Food99AuthService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Lojas de marketplace (iFood e 99Food) de todas as empresas, uma linha por filial conectada.
 * As duas tabelas têm colunas diferentes: cada uma vira uma linha no mesmo formato e a lista
 * é filtrada e paginada em memória.
 */
class Connections extends Component
{
    use RequiresSuperAdmin;
    use WithPagination;

    private const PER_PAGE = 25;

    #[Url(as: 'busca')]
    public string $search = '';

    #[Url(as: 'canal')]
    public string $channel = '';

    #[Url(as: 'situacao')]
    public string $state = '';

    /** Lojas que autorizaram o app VEDDI e ainda não estão ligadas a nenhuma filial. */
    #[Locked]
    public array $unlinkedShops = [];

    #[Locked]
    public bool $unlinkedLoaded = false;

    public ?string $notice = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingChannel(): void
    {
        $this->resetPage();
    }

    public function updatingState(): void
    {
        $this->resetPage();
    }

    public function loadUnlinkedShops(): void
    {
        abort_unless(config('food99.enabled'), 404);

        try {
            $this->unlinkedShops = app(Food99AuthService::class)->authorizedShops();
            $this->unlinkedLoaded = true;
            $this->notice = null;
        } catch (Throwable $e) {
            $this->notice = Food99ApiException::messageFor($e);
        }
    }

    public function render()
    {
        $rows = $this->rows()
            ->when($this->channel !== '', fn (Collection $rows) => $rows->where('channel', $this->channel))
            ->when($this->state !== '', fn (Collection $rows) => $rows->where('state', $this->state))
            ->when($this->search !== '', function (Collection $rows) {
                $term = mb_strtolower($this->search);

                return $rows->filter(fn (array $row) => str_contains(mb_strtolower($row['company'].' '.$row['branch'].' '.$row['store'].' '.$row['store_id']), $term));
            })
            ->sortBy([['company', 'asc'], ['branch', 'asc'], ['channel', 'asc']])
            ->values();

        $page = $this->getPage();

        return view('livewire.super-admin.integrations.connections', [
            'connections' => new LengthAwarePaginator($rows->forPage($page, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page),
            'food99Enabled' => (bool) config('food99.enabled'),
        ])->layout('layouts.app', ['title' => 'Super Admin — Integrações']);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        // Backoffice: enxerga todas as empresas de propósito.
        $ifood = IfoodIntegration::withoutGlobalScopes()
            ->with(['company:id,name', 'branch' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'name')])
            ->whereNotNull('merchant_id')
            ->get()
            ->map(fn (IfoodIntegration $integration) => [
                'key' => "ifood-{$integration->id}",
                'channel' => 'ifood',
                'channel_label' => 'iFood',
                'company_id' => $integration->company_id,
                'company' => $integration->company?->name ?? "Empresa #{$integration->company_id}",
                'branch' => $integration->branch?->name ?? "Filial #{$integration->branch_id}",
                'store' => $integration->merchant_name ?: 'Loja',
                'store_id' => (string) $integration->merchant_id,
                'state' => match ($integration->status) {
                    'active' => 'connected',
                    'paused' => 'paused',
                    'reauthorization_required' => 'error',
                    default => 'pending',
                },
                'last_event_at' => $integration->last_webhook_received_at ?? $integration->last_polled_at,
                'token_expires_at' => $integration->token_expires_at,
            ]);

        $food99 = Food99Integration::withoutGlobalScopes()
            ->with(['company:id,name', 'branch' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'name')])
            ->where(fn ($query) => $query->whereNotNull('shop_id')->orWhere('status', Food99Integration::STATUS_PENDING_AUTHORIZATION))
            ->get()
            ->map(fn (Food99Integration $integration) => [
                'key' => "99food-{$integration->id}",
                'channel' => '99food',
                'channel_label' => '99Food',
                'company_id' => $integration->company_id,
                'company' => $integration->company?->name ?? "Empresa #{$integration->company_id}",
                'branch' => $integration->branch?->name ?? "Filial #{$integration->branch_id}",
                'store' => $integration->shop_name ?: 'Loja',
                'store_id' => (string) $integration->shop_id,
                'state' => match ($integration->status) {
                    Food99Integration::STATUS_ACTIVE => 'connected',
                    Food99Integration::STATUS_PAUSED => 'paused',
                    Food99Integration::STATUS_ERROR => 'error',
                    default => 'pending',
                },
                'last_event_at' => $integration->last_webhook_received_at,
                'token_expires_at' => $integration->token_expires_at,
            ]);

        return $ifood->toBase()->concat($food99->toBase());
    }
}
