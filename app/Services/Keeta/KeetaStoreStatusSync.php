<?php

namespace App\Services\Keeta;

use App\Contracts\KeetaGatewayContract;
use App\Exceptions\KeetaApiException;
use App\Models\Branch;
use App\Models\BranchPause;
use App\Models\KeetaIntegration;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Abre/fecha a loja na Keeta (merchantUpdate só com merchantStatus). Usado pela aba Loja, pela
 * suspensão da integração e pelas pausas da filial marcadas "Fechar também na Keeta"
 * (keeta_sync). A Keeta pede merchantStatus só pra fechamento inesperado; feriado e horário
 * normal vão pelos horários do cardápio.
 */
class KeetaStoreStatusSync
{
    public function __construct(
        private readonly KeetaGatewayContract $gateway,
        private readonly KeetaCatalogSyncService $catalog,
    ) {}

    public function integrationFor(Branch $branch): ?KeetaIntegration
    {
        $integration = KeetaIntegration::withoutGlobalScopes()
            ->where('company_id', $branch->company_id)
            ->where('branch_id', $branch->id)
            ->where('status', KeetaIntegration::STATUS_ACTIVE)
            ->whereNotNull('keeta_merchant_id')
            ->first();

        return $integration?->isOperational() ? $integration : null;
    }

    public function setOpen(KeetaIntegration $integration, bool $open): void
    {
        $status = $open ? 'AVAILABLE' : 'UNAVAILABLE';
        $this->gateway->merchantUpdate($integration, ['merchantStatus' => $status]);

        $integration->forceFill(['store_status' => $status, 'store_status_updated_at' => now()])->save();
        $this->catalog->forget($integration);

        Log::channel('keeta')->info('Keeta: loja '.($open ? 'aberta' : 'fechada'), [
            'keeta_integration_id' => $integration->id,
            'user_id' => auth()->id(),
        ]);
    }

    /** Pausa da filial replicada na Keeta: fecha agora se a pausa já começou. */
    public function mirror(BranchPause $pause, KeetaIntegration $integration): void
    {
        $pause->forceFill(['keeta_sync' => true, 'keeta_sync_state' => 'scheduled'])->save();
        $this->applyDuePauses($pause->branch_id);
    }

    /** Pausa removida: reabre na Keeta se ela era a que estava fechando a loja. false = falhou. */
    public function remove(BranchPause $pause): bool
    {
        if (! $pause->keeta_sync) {
            return true;
        }

        $wasClosing = $pause->keeta_sync_state === 'applied';
        $pause->forceFill(['keeta_sync' => false, 'keeta_sync_state' => null])->save();

        if (! $wasClosing) {
            return true;
        }

        $branch = Branch::withoutGlobalScopes()->find($pause->branch_id);
        $integration = $branch ? $this->integrationFor($branch) : null;
        if (! $integration || $this->hasActiveMirroredPause($pause->branch_id)) {
            return true;
        }

        try {
            $this->setOpen($integration, true);

            return true;
        } catch (Throwable $e) {
            Log::channel('keeta')->warning('Keeta: não reabriu a loja ao remover pausa', ['branch_id' => $pause->branch_id, 'error' => KeetaApiException::forLog($e)]);

            return false;
        }
    }

    /**
     * Agendado a cada minuto (todas as filiais) e logo ao cadastrar a pausa (só a filial dela):
     * fecha na Keeta as pausas espelhadas que começaram e reabre as que terminaram.
     */
    public function applyDuePauses(?int $branchId = null): void
    {
        BranchPause::query()
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->where('keeta_sync', true)
            ->whereIn('keeta_sync_state', ['scheduled', 'applied'])
            ->get()
            ->groupBy('branch_id')
            ->each(function ($pauses, $branchId) {
                $branch = Branch::withoutGlobalScopes()->find($branchId);
                $integration = $branch ? $this->integrationFor($branch) : null;
                if (! $integration) {
                    return;
                }

                $active = $pauses->first(fn (BranchPause $pause) => $pause->coversAt(now()));
                $applied = $pauses->where('keeta_sync_state', 'applied');

                try {
                    if ($active && $active->keeta_sync_state !== 'applied') {
                        $this->setOpen($integration, false);
                        $active->forceFill(['keeta_sync_state' => 'applied'])->save();
                    }

                    if (! $active && $applied->isNotEmpty()) {
                        $this->setOpen($integration, true);
                        // Pausa anual volta a valer no ano seguinte.
                        $applied->each(fn (BranchPause $pause) => $pause->forceFill(['keeta_sync_state' => $pause->recurring_annual ? 'scheduled' : 'done'])->save());
                    }
                } catch (Throwable $e) {
                    Log::channel('keeta')->warning('Keeta: falha ao aplicar pausa da filial', ['branch_id' => $branchId, 'error' => KeetaApiException::forLog($e)]);
                }
            });
    }

    private function hasActiveMirroredPause(int $branchId): bool
    {
        return BranchPause::query()->where('branch_id', $branchId)->where('keeta_sync', true)
            ->get()->contains(fn (BranchPause $pause) => $pause->coversAt(now()));
    }
}
