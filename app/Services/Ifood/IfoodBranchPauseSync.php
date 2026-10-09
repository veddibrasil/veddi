<?php

namespace App\Services\Ifood;

use App\Contracts\IfoodGatewayContract;
use App\Exceptions\IfoodApiException;
use App\Models\Branch;
use App\Models\BranchPause;
use App\Models\IfoodIntegration;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Replica uma pausa da filial (Filiais › Pausas e feriados) como interrupção da loja no iFood,
 * pra o painel e o app do iFood fecharem juntos. Opcional e só pra pausa pontual: o iFood não
 * tem pausa recorrente e limita cada interrupção a 7 dias.
 */
class IfoodBranchPauseSync
{
    public const MAX_MINUTES = 10080;

    public function __construct(private readonly IfoodGatewayContract $gateway) {}

    /** Integração da filial que pode receber a pausa (loja ativa ou suspensa, empresa com o módulo). */
    public function integrationFor(Branch $branch): ?IfoodIntegration
    {
        $integration = IfoodIntegration::withoutGlobalScopes()
            ->where('company_id', $branch->company_id)
            ->where('branch_id', $branch->id)
            ->whereNotNull('merchant_id')
            ->whereIn('status', ['active', 'paused'])
            ->first();

        return $integration && $branch->company?->canUseIfoodIntegration() ? $integration : null;
    }

    /** Motivo pelo qual a pausa não pode ir pro iFood, ou null se pode. */
    public function rejectionReason(BranchPause $pause): ?string
    {
        if ($pause->recurring_annual) {
            return 'O iFood não aceita pausa que se repete todo ano.';
        }
        if ($pause->ends_at->isPast()) {
            return 'A pausa precisa terminar no futuro para fechar a loja no iFood.';
        }
        if ($pause->starts_at->diffInMinutes($pause->ends_at) > self::MAX_MINUTES) {
            return 'O iFood aceita pausas de até 7 dias.';
        }

        return null;
    }

    /** Cria a interrupção no iFood e guarda o id na pausa. Lança se o iFood recusar. */
    public function mirror(BranchPause $pause, IfoodIntegration $integration): void
    {
        if ($reason = $this->rejectionReason($pause)) {
            throw new RuntimeException($reason);
        }

        // O iFood interpreta start/end no fuso da loja e descarta o offset: envia o horário de parede.
        $timezone = config('ifood.timezone');
        $start = $pause->starts_at->isPast() ? now()->addMinute() : $pause->starts_at->copy();
        $result = $this->gateway->createInterruption($integration, [
            'description' => mb_substr($pause->reason ?: 'Pausa da filial', 0, 255),
            'start' => $start->timezone($timezone)->format('Y-m-d\TH:i:s'),
            'end' => $pause->ends_at->copy()->timezone($timezone)->format('Y-m-d\TH:i:s'),
        ]);

        $pause->update(['ifood_interruption_id' => $result['id'] ?? null]);
    }

    /** Remove a interrupção replicada, se houver. Falha no iFood não impede excluir a pausa local. */
    public function remove(BranchPause $pause): bool
    {
        // Pausa já encerrada não tem o que reabrir no iFood.
        if (! $pause->ifood_interruption_id || $pause->ends_at->isPast()) {
            return true;
        }

        $integration = $this->integrationFor($pause->branch);
        if (! $integration) {
            return true;
        }

        try {
            $this->gateway->deleteInterruption($integration, $pause->ifood_interruption_id);

            return true;
        } catch (IfoodApiException $e) {
            // Já removida no iFood (pela aba Pausas da integração ou pelo Portal do Parceiro).
            if ($e->status === 404) {
                return true;
            }
            $this->logRemoveFailure($pause, $integration, $e);

            return false;
        } catch (Throwable $e) {
            $this->logRemoveFailure($pause, $integration, $e);

            return false;
        }
    }

    private function logRemoveFailure(BranchPause $pause, IfoodIntegration $integration, Throwable $e): void
    {
        Log::channel('ifood')->warning('iFood: não foi possível remover a pausa replicada da filial', [
            'branch_pause_id' => $pause->id,
            'ifood_integration_id' => $integration->id,
            'error' => $e->getMessage(),
        ]);
    }
}
