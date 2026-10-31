<?php

namespace App\Services\Food99;

use App\Contracts\Food99GatewayContract;
use App\Exceptions\Food99ApiException;
use App\Models\Branch;
use App\Models\BranchPause;
use App\Models\Food99Integration;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Status da loja na 99Food (setStatus) e pausas da filial replicadas nela.
 *
 * A 99 não tem interrupção com início e fim arbitrários como o iFood: pausa curta (até 30 min)
 * vira "pausar" com pause_time, até o fim do dia vira pause_time 4 e o resto vira "fechar"
 * (store_status 2) no início e "revogar fechamento" (4) no fim, aplicados pelo agendador.
 * O efeito do setStatus é assíncrono: a confirmação chega no callback shopStatus.
 */
class Food99StoreStatusSync
{
    public const STATUS_ONLINE = 1;

    public const STATUS_CLOSE = 2;

    public const STATUS_PAUSE = 3;

    public const STATUS_REVOKE_CLOSE = 4;

    public const PAUSE_TIMES = [1 => '10 minutos', 2 => '20 minutos', 3 => '30 minutos', 4 => 'Até o fim do dia'];

    public const PAUSE_REASONS = [
        1001 => 'Equipamento com defeito ou falta de luz/água/gás',
        1002 => 'Falta temporária de equipe',
        1003 => 'Ingredientes insuficientes',
        1004 => 'Risco de não entregar perto do fechamento',
        1005 => 'Muitos pedidos no salão',
        1006 => 'Outro motivo',
    ];

    public const DEFAULT_PAUSE_REASON = 1006;

    private const ERRNO_STORE_NOT_CLOSED = 56101;

    public function __construct(private readonly Food99GatewayContract $gateway) {}

    /** Integração da filial que pode receber a pausa (ativa ou suspensa, empresa com o módulo). */
    public function integrationFor(Branch $branch): ?Food99Integration
    {
        if (! config('food99.enabled')) {
            return null;
        }

        $integration = Food99Integration::withoutGlobalScopes()
            ->where('company_id', $branch->company_id)
            ->where('branch_id', $branch->id)
            ->whereNotNull('shop_id')
            ->whereIn('status', [Food99Integration::STATUS_ACTIVE, Food99Integration::STATUS_PAUSED])
            ->first();

        return $integration && $branch->company?->canUseMarketplaceIntegrations() ? $integration : null;
    }

    public function open(Food99Integration $integration): void
    {
        $this->send($integration, ['store_status' => self::STATUS_ONLINE], 'abrir');
    }

    public function close(Food99Integration $integration): void
    {
        $this->send($integration, ['store_status' => self::STATUS_CLOSE], 'fechar');
    }

    /** Desfaz um "fechar" (que só reabre manualmente ou por aqui). */
    public function revokeClose(Food99Integration $integration): void
    {
        $this->send($integration, ['store_status' => self::STATUS_REVOKE_CLOSE], 'reabrir');
    }

    public function pause(Food99Integration $integration, int $pauseTime, int $reasonCode = self::DEFAULT_PAUSE_REASON): void
    {
        if (! isset(self::PAUSE_TIMES[$pauseTime]) || ! isset(self::PAUSE_REASONS[$reasonCode])) {
            throw new InvalidArgumentException('Escolha a duração e o motivo da pausa.');
        }
        if ($integration->sub_biz_status === 3) {
            throw new RuntimeException('A loja está fechada na 99Food: reabra antes de pausar.');
        }

        $this->send($integration, [
            'store_status' => self::STATUS_PAUSE,
            'pause_time' => $pauseTime,
            'pause_reason_code' => $reasonCode,
        ], 'pausar');
    }

    /** Modo ocupado: a 99 soma o atraso ao tempo de preparo informado ao cliente. */
    public function busy(Food99Integration $integration, int $minutes, int $cookDelay): void
    {
        if ($minutes < 1 || $minutes > 180 || $cookDelay < 1 || $cookDelay > 30) {
            throw new InvalidArgumentException('Modo ocupado: duração de 1 a 180 minutos e atraso de 1 a 30 minutos.');
        }

        $busyMode = [
            'busy_state' => 1,
            'busy_duration_minutes' => $minutes,
            'busy_cook_delay_minutes' => $cookDelay,
        ];

        $this->send($integration, ['busy_mode' => $busyMode], 'modo ocupado');

        // O callback shopStatus não informa modo ocupado e shop/detail só aceita 1 leitura por
        // minuto: grava aqui, com o fim calculado, porque a 99 volta ao normal sozinha.
        $integration->forceFill(['busy_mode' => $busyMode + ['until' => now()->addMinutes($minutes)->getTimestamp()]])->save();
    }

    public function endBusy(Food99Integration $integration): void
    {
        $this->send($integration, ['busy_mode' => ['busy_state' => 0]], 'encerrar modo ocupado');
        $integration->forceFill(['busy_mode' => ['busy_state' => 0]])->save();
    }

    /** Motivo pelo qual a pausa não pode ir pra 99Food, ou null se pode. */
    public function rejectionReason(BranchPause $pause): ?string
    {
        if ($pause->recurring_annual) {
            return 'A 99Food não aceita pausa que se repete todo ano.';
        }
        if ($pause->ends_at->isPast()) {
            return 'A pausa precisa terminar no futuro para fechar a loja na 99Food.';
        }

        return null;
    }

    /** Marca a pausa pra replicar na 99 e aplica já se ela começou. Lança se a 99 recusar. */
    public function mirror(BranchPause $pause, Food99Integration $integration): void
    {
        if ($reason = $this->rejectionReason($pause)) {
            throw new RuntimeException($reason);
        }

        $pause->update(['food99_sync' => true, 'food99_sync_state' => 'scheduled']);

        if ($pause->starts_at->lte(now())) {
            $this->apply($pause, $integration);
        }
    }

    /**
     * Agendador (a cada minuto): começa as pausas replicadas que chegaram no horário e reabre
     * as fechadas que terminaram.
     */
    public function applyDuePauses(): void
    {
        BranchPause::withoutGlobalScopes()
            ->where('food99_sync', true)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('food99_sync_state', 'scheduled')->where('starts_at', '<=', now()))
                ->orWhere(fn ($q) => $q->whereIn('food99_sync_state', ['closed', 'paused'])->where('ends_at', '<=', now())))
            ->lazyById()
            ->each(function (BranchPause $pause) {
                // Agendada que já terminou (agendador parado, 99 recusando): a loja nunca fechou
                // por ela, então não há o que reabrir.
                if ($pause->food99_sync_state === 'scheduled' && ! $pause->ends_at->isFuture()) {
                    $pause->update(['food99_sync_state' => 'skipped']);

                    return;
                }

                $integration = $this->integrationForPause($pause);
                if (! $integration) {
                    $pause->update(['food99_sync_state' => 'skipped']);

                    return;
                }

                try {
                    $pause->food99_sync_state === 'scheduled'
                        ? $this->apply($pause, $integration)
                        : $this->finish($pause, $integration);
                } catch (Throwable $e) {
                    Log::channel('food99')->warning('99Food: pausa agendada não aplicada, nova tentativa no próximo minuto', [
                        'branch_pause_id' => $pause->id,
                        'error' => Food99ApiException::forLog($e),
                    ]);
                }
            });
    }

    /** Pausa excluída antes do fim: reabre a loja na 99. False se a 99 recusar. */
    public function remove(BranchPause $pause): bool
    {
        if (! $pause->food99_sync || ! in_array($pause->food99_sync_state, ['closed', 'paused'], true) || $pause->ends_at->isPast()) {
            return true;
        }

        $integration = $this->integrationForPause($pause);
        if (! $integration) {
            return true;
        }

        try {
            $this->finish($pause, $integration);

            return true;
        } catch (Throwable $e) {
            Log::channel('food99')->warning('99Food: não foi possível reabrir a loja ao remover a pausa', [
                'branch_pause_id' => $pause->id,
                'food99_integration_id' => $integration->id,
                'error' => Food99ApiException::forLog($e),
            ]);

            return false;
        }
    }

    /** Fora de request (agendador): filial sem escopo de empresa, dona é a própria pausa. */
    private function integrationForPause(BranchPause $pause): ?Food99Integration
    {
        $branch = Branch::withoutGlobalScopes()->where('company_id', $pause->company_id)->find($pause->branch_id);

        return $branch ? $this->integrationFor($branch) : null;
    }

    private function apply(BranchPause $pause, Food99Integration $integration): void
    {
        $minutes = (int) ceil(now()->diffInMinutes($pause->ends_at, true));
        $endsToday = $pause->ends_at->copy()->timezone(config('food99.timezone'))->isSameDay(now()->timezone(config('food99.timezone')));

        if ($minutes <= 30 || $endsToday) {
            $pauseTime = match (true) {
                $minutes <= 10 => 1,
                $minutes <= 20 => 2,
                $minutes <= 30 => 3,
                default => 4,
            };
            $this->pause($integration, $pauseTime);
            $pause->update(['food99_sync_state' => 'paused']);

            return;
        }

        $this->close($integration);
        $pause->update(['food99_sync_state' => 'closed']);
    }

    private function finish(BranchPause $pause, Food99Integration $integration): void
    {
        if ($pause->food99_sync_state === 'closed') {
            try {
                // [VALIDAR 99Food] se "revogar fechamento" já volta a loja pro horário automático.
                $this->revokeClose($integration);
            } catch (Food99ApiException $e) {
                // Reaberta antes do fim da pausa (aqui ou no app): não há fechamento a desfazer.
                if ($e->errno !== self::ERRNO_STORE_NOT_CLOSED) {
                    throw $e;
                }
            }
        } else {
            $this->open($integration);
        }

        $pause->update(['food99_sync_state' => 'done']);
    }

    private function send(Food99Integration $integration, array $params, string $action): void
    {
        $this->gateway->setStoreStatus($integration, $params);
        $integration->forceFill(['store_status_updated_at' => now()])->saveQuietly();

        Log::channel('food99')->info("99Food: pedido pra {$action} a loja enviado", [
            'food99_integration_id' => $integration->id,
            'store_status' => $params['store_status'] ?? null,
        ]);
    }
}
