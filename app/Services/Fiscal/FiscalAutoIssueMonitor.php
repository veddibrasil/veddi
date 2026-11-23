<?php

namespace App\Services\Fiscal;

use App\Models\Branch;
use App\Models\CompanyFiscalConfig;
use App\Models\CompanyNotification;
use App\Models\FiscalNote;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Falhas da emissão automática sem lotar o sino e sem insistir na SEFAZ:
 * - resumo: um aviso por empresa a cada hora, atualizado com o total de pedidos que não saíram;
 * - disjuntor: N notas automáticas rejeitadas seguidas na mesma filial (fiscal.auto_issue.breaker_threshold)
 *   pausam a emissão automática dela até alguém retomar em Fiscal › Configurações. Rejeição em
 *   sequência quase sempre é do emitente (credenciamento, CSC, certificado) e se repetiria em
 *   todo pedido.
 */
class FiscalAutoIssueMonitor
{
    public const FAILED_TYPE = 'fiscal_auto_failed';

    public const PAUSED_TYPE = 'fiscal_auto_paused';

    /** Pedido que a emissão automática não conseguiu emitir antes do envio (pendência, configuração). */
    public function orderFailed(Order $order, string $message): void
    {
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['fiscal_error' => $message]);

        Log::channel('fiscal')->warning('Emissão automática: pedido não emitido', [
            'order_id' => $order->id,
            'company_id' => $order->company_id,
            'reason' => $message,
        ]);

        $this->notifyFailures($order->company_id);
    }

    /** Nota automática rejeitada ou com erro (resposta da emissão ou webhook). */
    public function noteFailed(FiscalNote $note): void
    {
        if (! ($note->data['automatic'] ?? false) || ! $note->company_id) {
            return;
        }

        $this->notifyFailures($note->company_id);
        $this->tripBreakerIfNeeded($note);
    }

    private function notifyFailures(int $companyId): void
    {
        $since = now()->subHour();
        // Só o que a emissão automática tentou: notas marcadas 'automatic' que voltaram com erro
        // e pedidos que ela reservou e pararam antes do envio (sem nota). O PDV marca
        // fiscal_auto_skip, então nota do caixa nunca entra aqui.
        $rejected = FiscalNote::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', ['rejected', 'error'])
            ->where('updated_at', '>=', $since)
            ->get(['id', 'order_id', 'data'])
            ->filter(fn (FiscalNote $note) => (bool) ($note->data['automatic'] ?? false))
            ->pluck('order_id')
            ->unique()
            ->count();
        $notSent = Order::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('fiscal_auto_attempted_at', '>=', $since)
            ->where('fiscal_auto_skip', false)
            ->whereNotNull('fiscal_error')
            ->whereDoesntHave('fiscalNotes', fn (Builder $notes) => $notes->withoutGlobalScopes())
            ->count();
        $failures = $rejected + $notSent;

        if ($failures === 0) {
            return;
        }

        $attributes = [
            'title' => 'Notas fiscais automáticas não emitidas',
            'subtitle' => $failures.' pedido(s) na última hora ficaram sem nota. Confira o motivo e emita em "Com erro" no Controle de notas.',
            // O Controle abre no dia de hoje; pedido pago hoje pode ter sido criado antes.
            'link' => route('admin.fiscal.control', [
                'situationFilter' => 'failed',
                'dateFrom' => now()->subDays(max(1, (int) config('fiscal.auto_issue.lookback_days', 3)))->toDateString(),
                'dateTo' => now()->toDateString(),
            ]),
        ];

        $open = CompanyNotification::where('company_id', $companyId)
            ->where('type', self::FAILED_TYPE)
            ->whereNull('read_at')
            ->where('created_at', '>=', now()->subHour())
            ->latest('id')
            ->first();

        $open
            ? $open->update($attributes)
            : CompanyNotification::create([...$attributes, 'company_id' => $companyId, 'type' => self::FAILED_TYPE]);
    }

    private function tripBreakerIfNeeded(FiscalNote $note): void
    {
        $threshold = max(1, (int) config('fiscal.auto_issue.breaker_threshold', 3));
        if (! $note->fiscal_config_id) {
            return;
        }

        // Últimas notas automáticas da filial desde a ativação ou a última retomada (rejeição de
        // antes da correção não conta de novo); 'automatic' fica no JSON, filtrado aqui.
        $since = CompanyFiscalConfig::where('company_id', $note->company_id)->find($note->fiscal_config_id)?->autoIssueBreakerSince();
        $recent = FiscalNote::withoutGlobalScopes()
            ->where('company_id', $note->company_id)
            ->where('fiscal_config_id', $note->fiscal_config_id)
            ->when($since, fn (Builder $query) => $query->where('created_at', '>=', $since))
            ->latest('id')
            ->limit($threshold * 5)
            ->get(['id', 'status', 'data'])
            ->filter(fn (FiscalNote $recentNote) => (bool) ($recentNote->data['automatic'] ?? false))
            ->take($threshold);

        if ($recent->count() < $threshold || $recent->contains(fn (FiscalNote $recentNote) => ! in_array($recentNote->status, ['rejected', 'error'], true))) {
            return;
        }

        $reason = Str::limit((string) ($note->error_message ?: 'Notas rejeitadas em sequência.'), 250);
        $paused = CompanyFiscalConfig::whereKey($note->fiscal_config_id)
            ->where('company_id', $note->company_id)
            ->whereNull('auto_issue_paused_at')
            ->update(['auto_issue_paused_at' => now(), 'auto_issue_pause_reason' => $reason]);

        if (! $paused) {
            return;
        }

        $config = CompanyFiscalConfig::find($note->fiscal_config_id);
        $branchName = $config?->branch_id
            ? Branch::withoutGlobalScopes()->where('company_id', $note->company_id)->whereKey($config->branch_id)->value('name')
            : null;

        Log::channel('fiscal')->error('Emissão automática pausada: rejeições seguidas', [
            'company_id' => $note->company_id,
            'fiscal_config_id' => $note->fiscal_config_id,
            'threshold' => $threshold,
            'last_fiscal_note_id' => $note->id,
        ]);

        CompanyNotification::create([
            'company_id' => $note->company_id,
            'type' => self::PAUSED_TYPE,
            'title' => 'Emissão automática de NFC-e pausada'.($branchName ? ' em '.$branchName : ''),
            'subtitle' => Str::limit($threshold.' notas seguidas rejeitadas: '.$reason.' Corrija e retome em Fiscal › Configurações.', 250),
            'link' => route('admin.fiscal.config'),
        ]);
    }
}
