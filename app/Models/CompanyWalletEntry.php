<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Ledger de movimentações da carteira por empresa.
// Tipos: credit (+), fee/withdrawal/refund/anticipation_fee/pix_fee/card_fee (−).
// NÃO é a fonte de verdade para saldo operacional — use BalanceService::calculateBalance().
// Esta tabela serve para exibir o histórico no painel e para auditoria/reconciliação.
class CompanyWalletEntry extends Model
{
    protected $fillable = [
        'company_id',
        'order_id',
        'type',
        'amount',
        'description',
        // Rastreia external_id ou vindi_transaction_token do pagamento, ou withdrawal_id para saques.
        // Usado como chave de idempotência em WalletService para evitar double-credit em retentativas.
        'reference',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Ledger balance for a company — for audit and testing only.
     * Operational balance (UI/withdrawals) must use BalanceService::calculateBalance(),
     * which reads from CompanyTransaction (the canonical source of truth).
     */
    public static function balanceFor(int $companyId): float
    {
        return (float) self::where('company_id', $companyId)
            ->selectRaw("SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END) as balance")
            ->value('balance') ?? 0.0;
    }
}
