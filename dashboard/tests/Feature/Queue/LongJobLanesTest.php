<?php

declare(strict_types=1);

use App\Jobs\Alerts\DeliverAlertJob;
use App\Jobs\Backups\RunControlPlaneBackupJob;
use App\Jobs\Updates\RunControlPlaneUpdateJob;
use App\Support\Queue\JobLanes;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\QueueRoutes;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Sleep;
use Symfony\Component\Finder\Finder;

/**
 * CP6 (G35): a job's timeout must stay below its connection's retry_after.
 * Otherwise a second worker takes the reserved job again while the first
 * still runs it: Laravel's worker then fails it (attempts > tries) or runs
 * it twice.
 */

/**
 * The timeout a job class runs with: its #[Timeout] attribute, else the
 * `timeout` property its constructor sets (constructed with dummy ids).
 */
function jobTimeout(string $class): ?int
{
    $reflection = new ReflectionClass($class);
    $attribute = $reflection->getAttributes(Timeout::class)[0] ?? null;

    if ($attribute !== null) {
        return $attribute->newInstance()->timeout;
    }

    if (! $reflection->hasProperty('timeout')) {
        return null;
    }

    $arguments = array_map(static fn (ReflectionParameter $parameter): mixed => match ((string) $parameter->getType()) {
        'int' => 1,
        'string' => 'x',
        'bool' => false,
        default => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
    }, $reflection->getConstructor()?->getParameters() ?? []);

    try {
        $job = $reflection->newInstanceArgs($arguments);
    } catch (Throwable) {
        return null;
    }

    $timeout = $reflection->getProperty('timeout')->getValue($job);

    return is_int($timeout) ? $timeout : null;
}

/**
 * @return list<class-string>
 */
function jobClasses(): array
{
    $classes = [];

    foreach (Finder::create()->files()->in(app_path('Jobs'))->name('*.php') as $file) {
        $class = 'App\\Jobs\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (class_exists($class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

test('a reserved job comes back to a second worker once retry_after passes (the CP6 reproduction)', function (): void {
    // A throwaway queue on its own Valkey database, nothing a dev worker reads.
    $manager = new RedisManager(app(), (string) config('database.redis.client', 'phpredis'), [
        'cp6' => [...(array) config('database.redis.default'), 'database' => 14, 'timeout' => 1, 'read_timeout' => 2, 'max_retries' => 0],
    ]);

    try {
        $manager->connection('cp6')->command('ping');
    } catch (Throwable) {
        $this->markTestSkipped('No Valkey to reproduce against.');
    }

    $queue = new RedisQueue($manager, 'cp6-'.bin2hex(random_bytes(4)), 'cp6', retryAfter: 1);
    $queue->setContainer(app());
    $queue->setConnectionName('cp6');

    try {
        $queue->pushRaw((string) json_encode(['uuid' => 'cp6', 'displayName' => RunControlPlaneBackupJob::class, 'job' => 'x', 'maxTries' => 1, 'timeout' => 21600, 'data' => [], 'attempts' => 0]));

        $first = $queue->pop();
        expect($first?->attempts())->toBe(1)
            ->and($queue->pop())->toBeNull();

        // Worker A is still running it when retry_after passes.
        Sleep::sleep(2);
        $again = $queue->pop();

        expect($again)->not->toBeNull()
            ->and($again?->attempts())->toBe(2)
            ->and($again?->maxTries())->toBe(1);
    } finally {
        $manager->connection('cp6')->command('flushdb');
        $manager->purge('cp6');
    }
});

test('every job’s timeout is below the retry_after of the connection it is routed to', function (): void {
    $routes = (array) config('queue.routes');
    $checked = 0;

    foreach (jobClasses() as $class) {
        $timeout = jobTimeout($class);
        $connection = is_array($routes[$class] ?? null) ? $routes[$class][0] : 'redis';
        $retryAfter = config()->integer("queue.connections.{$connection}.retry_after");

        // Without a timeout of its own the Horizon supervisor's (60 s) applies.
        expect($timeout ?? 60)->toBeLessThan($retryAfter, "{$class} ({$timeout} s) on {$connection} (retry_after {$retryAfter} s)");
        $checked++;
    }

    expect($checked)->toBeGreaterThan(30)
        // The 6-hour jobs are among them, on the long lane.
        ->and(jobTimeout(RunControlPlaneBackupJob::class))->toBe(config()->integer('launchroom.backups.task_timeout_seconds'))
        ->and($routes[RunControlPlaneBackupJob::class] ?? null)->toBe(['redis-long', 'long'])
        ->and($routes[RunControlPlaneUpdateJob::class] ?? null)->toBe(['redis-long', 'long'])
        ->and($routes[DeliverAlertJob::class] ?? null)->toBe(['redis', 'alerts']);
});

test('Horizon runs a supervisor per lane whose worker timeout fits its connection', function (): void {
    $defaults = (array) config('horizon.defaults');

    foreach (['production', 'local'] as $environment) {
        $supervisors = (array) config("horizon.environments.{$environment}");
        expect(array_keys($supervisors))->toContain('supervisor-1', 'supervisor-alerts', 'supervisor-long');
    }

    $long = (array) $defaults['supervisor-long'];
    expect($long['connection'])->toBe('redis-long')
        ->and($long['queue'])->toBe(['long'])
        ->and($long['timeout'])->toBeLessThan(config()->integer('queue.connections.redis-long.retry_after'))
        ->and($long['timeout'])->toBeGreaterThanOrEqual((int) jobTimeout(RunControlPlaneUpdateJob::class))
        ->and(((array) $defaults['supervisor-alerts'])['queue'])->toBe(['alerts']);
});

test('with Redis the routes pick the lane’s connection; without it only the queue name', function (): void {
    $routes = new QueueRoutes;
    config(['queue.default' => 'redis']);
    JobLanes::register(config(), $routes);

    $job = new RunControlPlaneBackupJob(1);
    expect($routes->getConnection($job))->toBe('redis-long')
        ->and($routes->getQueue($job))->toBe('long');

    $sync = new QueueRoutes;
    config(['queue.default' => 'sync']);
    JobLanes::register(config(), $sync);

    expect($sync->getConnection($job))->toBeNull()
        ->and($sync->getQueue($job))->toBe('long');
});
