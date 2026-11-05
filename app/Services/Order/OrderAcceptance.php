<?php

namespace App\Services\Order;

use App\Enums\OrderChannel;
use App\Exceptions\Food99ApiException;
use App\Exceptions\IfoodApiException;
use App\Exceptions\KeetaApiException;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Services\Food99\Food99OrderActionService;
use App\Services\Ifood\IfoodOrderActionService;
use App\Services\Keeta\KeetaOrderActionService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pedidos que esperam a loja aceitar, venham do chat, do iFood, da 99Food ou da Keeta, e quem pode
 * aceitá-los. É o que decide até quando o alarme do painel toca.
 */
class OrderAcceptance
{
    /** Papéis presos a uma estação: só tocam e aceitam pedidos com itens dela. */
    private const STATIONS = ['cozinha', 'bar'];

    public function canAccept(User $user, Company $company): bool
    {
        return $user->isSuperAdmin() || (
            $user->hasPermission('orders.view', $company)
            && $user->hasPermission('orders.update', $company)
            && $user->roleForCompany($company) !== 'entrega'
        );
    }

    public function canAcceptOrder(User $user, Order $order): bool
    {
        $company = $order->company;
        if (! $company || ! $this->canAccept($user, $company) || ! $user->canAccessBranch($company, $order->branch_id)) {
            return false;
        }

        $role = $user->roleForCompany($company);

        return ! in_array($role, self::STATIONS, true) || $order->hasItemsForStation($role);
    }

    /** @return Collection<int, Order> */
    public function awaitingFor(User $user, Company $company): Collection
    {
        if (! $this->canAccept($user, $company)) {
            return collect();
        }

        $role = $user->roleForCompany($company);
        $branchId = $user->lockedBranchId($company);

        return Order::where('company_id', $company->id)
            ->whereAwaitingAcceptance()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when(in_array($role, self::STATIONS, true), fn ($q) => $q->forStation($role))
            ->with(['customer', 'company'])
            ->oldest()
            ->get()
            ->reject(fn (Order $order) => $order->acceptanceExpired())
            ->values();
    }

    public function accept(Order $order, User $user): void
    {
        abort_unless($this->canAcceptOrder($user, $order), 403);

        try {
            match ($order->channel) {
                OrderChannel::Ifood->value => app(IfoodOrderActionService::class)->accept($order, $user->id),
                OrderChannel::Food99->value => app(Food99OrderActionService::class)->accept($order, $user->id),
                OrderChannel::Keeta->value => app(KeetaOrderActionService::class)->accept($order, $user->id),
                default => app(ChatOrderAcceptance::class)->accept($order, $user),
            };
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            if (! $order->isMarketplaceOrder()) {
                throw $e;
            }

            // Plataforma fora ou pedido já decidido nela: o alarme continua até a loja resolver.
            throw ValidationException::withMessages(['orderAcceptance' => match ($order->channel) {
                OrderChannel::Ifood->value => IfoodApiException::messageFor($e),
                OrderChannel::Keeta->value => KeetaApiException::messageFor($e),
                default => Food99ApiException::messageFor($e),
            }]);
        }
    }
}
