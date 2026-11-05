<?php

namespace App\Livewire\Admin;

use App\Models\Order;
use App\Services\Order\OrderAcceptance;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Alarme que toca até alguém aceitar o pedido. O layout exclui as telas do PDV;
 * o perfil de caixa também fica sem alarme, mesmo nas outras telas do painel.
 */
class OrderAcceptanceAlarm extends Component
{
    #[Locked]
    public int $companyId = 0;

    #[Locked]
    public bool $canAccept = false;

    /** Cozinha e bar aceitam, mas recusar é do balcão, como nas telas de pedido. */
    #[Locked]
    public bool $canReject = false;

    /**
     * Motivo do último aceite que falhou, por pedido. O poll limpa o bag de erros em segundos, e
     * a loja precisa ler o motivo (app 99 fechado, por exemplo) enquanto o pedido segue tocando.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $acceptanceErrors = [];

    public function mount(): void
    {
        if (app()->bound('current.company') && auth()->check()) {
            $company = app('current.company');
            if (auth()->user()->roleForCompany($company) === 'caixa') {
                return;
            }

            $this->companyId = $company->id;
            $this->canAccept = app(OrderAcceptance::class)->canAccept(auth()->user(), $company);
            $this->canReject = $this->canAccept && ! in_array(auth()->user()->roleForCompany($company), ['cozinha', 'bar'], true);
        }
    }

    public function getListeners(): array
    {
        if (! $this->canAccept) {
            return [];
        }

        return [
            'order-accepted' => '$refresh',
            "echo-private:orders.{$this->companyId},NewOrderPlaced" => '$refresh',
            "echo-private:orders.{$this->companyId},OrderStatusUpdated" => '$refresh',
            "echo-private:orders.{$this->companyId},IfoodOrderUpdated" => '$refresh',
            "echo-private:orders.{$this->companyId},Food99OrderUpdated" => '$refresh',
            "echo-private:orders.{$this->companyId},KeetaOrderUpdated" => '$refresh',
        ];
    }

    /** @return Collection<int, Order> */
    #[Computed]
    public function awaitingOrders(): Collection
    {
        $company = app()->bound('current.company') ? app('current.company') : null;
        $user = auth()->user();
        if (! $this->canAccept || ! $company || $company->id !== $this->companyId || ! $user
            || $user->roleForCompany($company) === 'caixa') {
            return collect();
        }

        return app(OrderAcceptance::class)->awaitingFor($user, $company);
    }

    public function acceptOrder(int $orderId): void
    {
        abort_unless($this->canAccept, 403);

        $order = Order::where('company_id', $this->companyId)->findOrFail($orderId);

        try {
            app(OrderAcceptance::class)->accept($order, auth()->user());
        } catch (ValidationException $e) {
            $this->acceptanceErrors[$orderId] = $e->validator->errors()->first();

            throw $e;
        }

        unset($this->acceptanceErrors[$orderId], $this->awaitingOrders);
        $this->dispatch('order-accepted');
    }

    /** O alarme aparece em toda tela, mas os modais de recusa são da tela do pedido. */
    public function rejectOrder(int $orderId): void
    {
        abort_unless($this->canReject, 403);

        $order = Order::where('company_id', $this->companyId)->findOrFail($orderId);
        $this->redirectRoute('admin.orders.show', ['order' => $order, 'recusar' => 1], navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.order-acceptance-alarm');
    }
}
