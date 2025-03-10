<?php

namespace App\Console\Commands;

use App\Jobs\GetProcessingUsageJob;
use App\Models\ProjectEnvironment;
use Illuminate\Console\Command;

class GetProcessingUsages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:get-processing-usages';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update processing usages for all environments';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $environments = ProjectEnvironment::all();

        $environments->each(function (ProjectEnvironment $environment) {
            GetProcessingUsageJob::dispatch($environment);
        });
    }
}
