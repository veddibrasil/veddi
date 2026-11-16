<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Histórico de uma aplicação de modelo fiscal: quem, quando, onde e os valores anteriores e
 * novos de cada produto, para conferência e para desfazer.
 */
class FiscalTemplateApplication extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'fiscal_template_id', 'template_name', 'user_id', 'branch_id', 'target', 'mode',
        'products_count', 'changes', 'reverted_at', 'reverted_by',
    ];

    protected $casts = [
        'changes' => 'array',
        'reverted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
