<?php

namespace App\Console\Commands;

use App\Models\EmailEvent;
use Illuminate\Console\Command;

class MarketingPruneEvents extends Command
{
    protected $signature = 'marketing:prune-events';

    protected $description = 'Apaga eventos de e-mail marketing antigos (os contadores das campanhas ficam).';

    public function handle(): int
    {
        $cutoff = now()->subMonths((int) config('marketing.events_retention_months', 12));
        $deleted = 0;

        do {
            $batch = EmailEvent::where('occurred_at', '<', $cutoff)->limit(5000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("{$deleted} evento(s) apagado(s).");

        return self::SUCCESS;
    }
}
