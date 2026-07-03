<?php

namespace App\Livewire\Admin\Portals;

use App\Models\Portal;
use App\Models\Product;
use App\Models\ProductPortalMapping;
use App\Services\Portal\IfoodService;
use App\Services\Portal\PortalGatewayFactory;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class Index extends Component
{
    use WithPagination;

    public bool $portalsEnabled = false;

    public Collection $branches;

    public ?Portal $portal = null;

    // --- Fluxo de conexão (aplicativo distribuído iFood: usuário autoriza no
    // Portal do Parceiro e cola de volta o código de autorização exibido lá) ---
    public ?int $connectingBranchId = null;

    public ?string $pendingUserCode = null;

    public ?string $pendingVerificationUrl = null;

    public ?string $pendingVerifier = null;

    public string $authorizationCode = '';

    public string $externalMerchantId = '';

    // --- Mapeamento de produtos ---
    public string $search = '';

    public array $mappingInputs = [];

    public function mount(): void
    {
        $company = app()->bound('current.company') ? app('current.company') : null;

        if ($company) {
            abort_unless(auth()->user()?->hasPermission('portals.manage', $company), 403);
        }

        $this->portalsEnabled = (bool) $company?->portals_module_enabled;
        $this->branches = $company ? $company->branches()->orderBy('name')->get() : collect();
        $this->portal = Portal::where('channel', 'ifood')->first();
    }

    public function startConnect(int $branchId): void
    {
        abort_unless($this->portalsEnabled, 403);

        $result = app(IfoodService::class)->requestUserCode();

        $this->connectingBranchId = $branchId;
        $this->pendingUserCode = $result['userCode'] ?? null;
        $this->pendingVerificationUrl = $result['verificationUrlComplete'] ?? ($result['verificationUrl'] ?? null);
        $this->pendingVerifier = $result['authorizationCodeVerifier'] ?? null;
    }

    public function confirmConnect(): void
    {
        abort_unless($this->portalsEnabled, 403);

        $this->validate([
            'authorizationCode' => ['required', 'string'],
            'externalMerchantId' => ['required', 'string'],
        ], [], [
            'authorizationCode' => 'código de autorização',
            'externalMerchantId' => 'ID da loja no iFood',
        ]);

        $company = app('current.company');

        try {
            $token = app(IfoodService::class)->exchangeAuthorizationCode(
                $this->authorizationCode,
                (string) $this->pendingVerifier,
            );
        } catch (RuntimeException $e) {
            $this->addError('authorizationCode', 'Não foi possível validar o código junto ao iFood: '.$e->getMessage());

            return;
        }

        $this->portal = Portal::updateOrCreate(
            ['channel' => 'ifood', 'external_merchant_id' => $this->externalMerchantId],
            [
                'company_id' => $company->id,
                'branch_id' => $this->connectingBranchId,
                'credentials' => [
                    'access_token' => $token['accessToken'],
                    'refresh_token' => $token['refreshToken'],
                    'expires_at' => now()->addSeconds((int) $token['expiresIn'])->toIso8601String(),
                ],
                'status' => 'connected',
            ]
        );

        $this->reset(['connectingBranchId', 'pendingUserCode', 'pendingVerificationUrl', 'pendingVerifier', 'authorizationCode', 'externalMerchantId']);

        session()->flash('status', 'iFood conectado com sucesso.');
    }

    public function disconnect(): void
    {
        abort_unless($this->portalsEnabled && $this->portal, 403);

        $this->portal->update(['status' => 'disconnected']);
        $this->portal = $this->portal->fresh();

        session()->flash('status', 'iFood desconectado.');
    }

    public function pauseOrders(int $minutes): void
    {
        abort_unless($this->portalsEnabled && $this->portal, 403);

        try {
            app(PortalGatewayFactory::class)->forAvailability($this->portal)->pauseReceivingOrders($minutes, 'Pausado manualmente pelo lojista');
        } catch (RuntimeException $e) {
            $this->addError('pause', 'Não foi possível pausar no iFood: '.$e->getMessage());

            return;
        }

        $this->portal = $this->portal->fresh();
        session()->flash('status', "Recebimento de pedidos iFood pausado por {$minutes} minutos.");
    }

    public function resumeOrders(): void
    {
        abort_unless($this->portalsEnabled && $this->portal, 403);

        try {
            app(PortalGatewayFactory::class)->forAvailability($this->portal)->resumeReceivingOrders();
        } catch (RuntimeException $e) {
            $this->addError('pause', 'Não foi possível retomar no iFood: '.$e->getMessage());

            return;
        }

        $this->portal = $this->portal->fresh();
        session()->flash('status', 'Recebimento de pedidos iFood retomado.');
    }

    public function saveMapping(int $productId): void
    {
        abort_unless($this->portalsEnabled && $this->portal, 403);

        $value = trim((string) ($this->mappingInputs[$productId] ?? ''));

        if ($value === '') {
            ProductPortalMapping::where('portal_id', $this->portal->id)
                ->where('product_id', $productId)
                ->delete();

            return;
        }

        try {
            ProductPortalMapping::updateOrCreate(
                ['portal_id' => $this->portal->id, 'product_id' => $productId],
                ['external_item_id' => $value],
            );
        } catch (\Illuminate\Database\QueryException $e) {
            $this->addError("mappingInputs.{$productId}", 'Esse ID de item do iFood já está associado a outro produto.');
        }
    }

    public function render()
    {
        $products = collect();

        if ($this->portal) {
            $products = Product::query()
                ->when($this->search !== '', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
                ->orderBy('name')
                ->with(['portalMappings' => fn ($q) => $q->where('portal_id', $this->portal->id)])
                ->paginate(20);

            foreach ($products as $product) {
                if (! array_key_exists($product->id, $this->mappingInputs)) {
                    $this->mappingInputs[$product->id] = $product->portalMappings->first()?->external_item_id ?? '';
                }
            }
        }

        return view('livewire.admin.portals.index', ['products' => $products])
            ->layout('layouts.app', ['title' => 'Portais']);
    }
}
