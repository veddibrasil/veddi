<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\AsaasService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BillAccumulatedFees extends Command
{
    protected $signature   = 'fees:bill';
    protected $description = 'Gera cobranças no Asaas pelas taxas de plataforma acumuladas dos pedidos pagos';

    public function handle(AsaasService $asaas): int
    {
        // Fetch all paid orders with unbilled platform fees, grouped by company
        $rows = Order::query()
            ->select('company_id', DB::raw('SUM(fee) as total_fee'), DB::raw('COUNT(*) as order_count'))
            ->where('status', 'paid')
            ->where('fee', '>', 0)
            ->whereNull('fee_billed_at')
            ->groupBy('company_id')
            ->with('company:id,name,asaas_customer_id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Nenhuma taxa pendente de faturamento.');
            return self::SUCCESS;
        }

        $billed = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $company = $row->company;

            if (! $company || ! $company->asaas_customer_id) {
                $this->warn("Empresa #{$row->company_id} sem asaas_customer_id — pulando.");
                $skipped++;
                continue;
            }

            $totalFee = round((float) $row->total_fee, 2);

            if ($totalFee <= 0) {
                $skipped++;
                continue;
            }

            try {
                $asaas->createCharge(
                    $company->asaas_customer_id,
                    $totalFee,
                    "Taxa de plataforma — {$row->order_count} pedido(s) — {$company->name}"
                );

                // Mark orders as billed
                Order::where('company_id', $row->company_id)
                    ->where('status', 'paid')
                    ->where('fee', '>', 0)
                    ->whereNull('fee_billed_at')
                    ->update(['fee_billed_at' => now()]);

                $this->info("Empresa {$company->name}: R$ " . number_format($totalFee, 2, ',', '.') . " cobrado.");
                $billed++;

                Log::channel('payments')->info('Taxa de plataforma faturada', [
                    'company_id'   => $company->id,
                    'company_name' => $company->name,
                    'total_fee'    => $totalFee,
                    'order_count'  => $row->order_count,
                ]);
            } catch (Throwable $e) {
                $this->error("Erro ao cobrar empresa {$company->name}: {$e->getMessage()}");
                Log::channel('payments')->error('Falha ao faturar taxa de plataforma', [
                    'company_id' => $company->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->info("Concluído: {$billed} empresa(s) faturada(s), {$skipped} pulada(s).");

        return self::SUCCESS;
    }
}
