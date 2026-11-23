<?php

namespace App\Console\Commands;

use App\Enums\OrderChannel;
use App\Jobs\AutoIssueFiscalNote;
use App\Models\CompanyFiscalConfig;
use App\Models\Order;
use App\Services\Fiscal\FiscalAutoIssuePolicy;
use Illuminate\Console\Command;

/**
 * Rede de segurança da NFC-e automática: pedidos que ficaram prontos sem passar pelo observer
 * (UPDATE direto, worker fora do ar, evento perdido). Só filiais com a emissão automática ativa,
 * só pedidos recentes (fiscal.auto_issue.lookback_days) e criados depois de ligar.
 */
class AutoIssueFiscalNotes extends Command
{
    protected $signature = 'fiscal:auto-issue {--dry-run : Só lista quantos pedidos seriam enfileirados}';

    protected $description = 'Enfileira a NFC-e automática dos pedidos prontos que ainda não foram tratados';

    /** Teto por filial e rodada: protege a fila e a Focus numa ativação com atraso acumulado. */
    private const MAX_PER_BRANCH = 300;

    public function handle(FiscalAutoIssuePolicy $policy): int
    {
        $configs = CompanyFiscalConfig::query()
            ->where('enabled', true)
            ->where('auto_issue_enabled', true)
            ->whereNull('auto_issue_paused_at')
            ->whereNotNull('branch_id')
            ->get();

        $queued = 0;
        $lookback = now()->subDays(max(1, (int) config('fiscal.auto_issue.lookback_days', 3)));

        foreach ($configs as $config) {
            $since = $config->auto_issue_enabled_at && $config->auto_issue_enabled_at->gt($lookback) ? $config->auto_issue_enabled_at : $lookback;

            $orders = Order::withoutGlobalScopes()
                ->where('company_id', $config->company_id)
                ->where('branch_id', $config->branch_id)
                ->whereNull('fiscal_auto_attempted_at')
                ->where('fiscal_auto_skip', false)
                ->where('is_open_tab', false)
                ->whereIn('status', Order::FISCAL_ISSUABLE_STATUSES)
                ->where(fn ($query) => $query->whereNull('channel')->orWhereNotIn('channel', OrderChannel::marketplaceValues()))
                ->where('created_at', '>=', $since)
                // Pré-filtro em SQL do que FiscalAutoIssuePolicy::evaluate() espera (a decisão é
                // dela): sem isso, pedidos esperando aceite ou pagamento ocupariam o teto da
                // rodada e seriam reavaliados a cada 5 minutos.
                ->where(fn ($query) => $query->where('order_type', 'pdv')->orWhereNotNull('chat_accepted_at')->orWhere('status', '!=', 'paid'))
                ->where(fn ($query) => $query
                    ->where(fn ($query) => $query->where('order_type', '!=', 'pdv')->where(fn ($query) => $query->whereNull('payment_on_delivery')->orWhere('payment_on_delivery', false)))
                    ->orWhereHas('payments'))
                ->whereDoesntHave('fiscalNotes', fn ($notes) => $notes->withoutGlobalScopes())
                ->with(['company', 'items'])
                ->oldest('id')
                ->limit(self::MAX_PER_BRANCH)
                ->get();

            foreach ($orders as $order) {
                $verdict = $policy->evaluate($order);
                if (! $verdict->settles()) {
                    continue;
                }

                $queued++;
                if (! $this->option('dry-run')) {
                    AutoIssueFiscalNote::dispatch($order->id);
                }
            }
        }

        $this->info(($this->option('dry-run') ? 'Seriam enfileirados: ' : 'Enfileirados: ').$queued.' pedido(s).');

        return self::SUCCESS;
    }
}
