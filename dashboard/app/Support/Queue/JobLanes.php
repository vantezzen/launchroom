<?php

declare(strict_types=1);

namespace App\Support\Queue;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Queue\QueueRoutes;

/**
 * Job lanes (config/queue.php `routes`, G35): long jobs on the `redis-long`
 * connection and its `long` queue, notifications on `alerts`. A reserved job
 * goes back to another worker once its connection's `retry_after` passes
 * while the first worker still runs it (failed with MaxAttemptsExceeded, or
 * run twice), so each lane's retry_after stays above its longest job.
 *
 * Without Redis as the default connection (tests run `sync`) only the queue
 * name is kept, so the jobs run where the default connection runs them.
 */
final class JobLanes
{
    public static function register(Config $config, QueueRoutes $routes): void
    {
        $redis = $config->get('queue.default') === 'redis';
        $lanes = [];

        foreach ((array) $config->get('queue.routes', []) as $class => $lane) {
            if (is_string($class) && is_array($lane) && count($lane) === 2) {
                $lanes[$class] = [$redis ? $lane[0] : null, $lane[1]];
            }
        }

        $routes->set($lanes);
    }
}
