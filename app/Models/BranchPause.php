<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchPause extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',
        'reason',
        'recurring_annual',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'recurring_annual' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function coversAt(CarbonInterface $when): bool
    {
        if (! $this->recurring_annual) {
            return $when->between($this->starts_at, $this->ends_at);
        }

        foreach ([$when->year - 1, $when->year, $when->year + 1] as $year) {
            $start = $this->starts_at->copy()->year($year);
            $end = $this->ends_at->copy()->year($year);

            if ($end->lt($start)) {
                $end = $end->addYear();
            }

            if ($when->between($start, $end)) {
                return true;
            }
        }

        return false;
    }
}
