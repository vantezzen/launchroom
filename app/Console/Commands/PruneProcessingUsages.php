<?php

namespace App\Console\Commands;

use App\Models\ProcessingUsage;
use Illuminate\Console\Command;

class PruneProcessingUsages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prune-processing-usages {--days=30}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune old processing usages';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = $this->option('days');

        $this->info("Pruning processing usages older than {$days} days...");

        $count = ProcessingUsage::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Pruned {$count} processing usages.");
    }
}
