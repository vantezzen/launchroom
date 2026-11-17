<?php

declare(strict_types=1);

use App\Jobs\Alerts\DeliverAlertJob;
use App\Jobs\Audit\VerifyAuditLogJob;
use App\Jobs\Backups\RunBackupMaintenanceJob;
use App\Jobs\Backups\RunControlPlaneBackupJob;
use App\Jobs\Backups\TestBackupDestinationJob;
use App\Jobs\Dockerfiles\DraftDockerfileJob;
use App\Jobs\Moves\PrepareHandoverJob;
use App\Jobs\Registry\CopyPrivateImageJob;
use App\Jobs\Security\EvaluateSecurityFindingsJob;
use App\Jobs\Security\SyncAdvisoriesJob;
use App\Jobs\Security\SyncFeedsJob;
use App\Jobs\Updates\RunControlPlaneUpdateJob;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        // launchroom: a reserved job comes back to another worker once
        // `retry_after` passes, while the first worker still runs it (it is
        // then failed with MaxAttemptsExceeded, or runs twice). So
        // retry_after stays above the longest job timeout on each
        // connection: up to 300 s here (AdvancePreviewsJob); longer jobs
        // go to `redis-long` through the routes below.
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 360),
            'block_for' => null,
            'after_commit' => false,
        ],

        // Backups, control-plane updates and hand-overs (up to the backup
        // timeout, 6 h, plus 5 min for updates), security feed syncs and
        // private image copies: their own Horizon supervisor, so they never
        // hold up deploys, and a retry_after above the longest of them.
        'redis-long' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => 'long',
            'retry_after' => (int) env('LAUNCHROOM_BACKUP_TIMEOUT', 6 * 3600) + 900,
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Job Lanes (launchroom)
    |--------------------------------------------------------------------------
    |
    | Job class => [connection, queue], registered in bootstrap/app.php.
    | `long`: jobs whose timeout is above the `redis` connection's
    | retry_after. `alerts`: notifications, so a backlog of other jobs never
    | delays them. Horizon runs one supervisor per lane (config/horizon.php).
    | When the default connection isn't Redis (tests run `sync`), only the
    | queue name applies.
    |
    */

    'routes' => [
        RunControlPlaneBackupJob::class => ['redis-long', 'long'],
        RunBackupMaintenanceJob::class => ['redis-long', 'long'],
        TestBackupDestinationJob::class => ['redis-long', 'long'],
        PrepareHandoverJob::class => ['redis-long', 'long'],
        RunControlPlaneUpdateJob::class => ['redis-long', 'long'],
        VerifyAuditLogJob::class => ['redis-long', 'long'],
        DraftDockerfileJob::class => ['redis-long', 'long'],
        SyncAdvisoriesJob::class => ['redis-long', 'long'],
        SyncFeedsJob::class => ['redis-long', 'long'],
        EvaluateSecurityFindingsJob::class => ['redis-long', 'long'],
        CopyPrivateImageJob::class => ['redis-long', 'long'],
        DeliverAlertJob::class => ['redis', 'alerts'],
    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],

];
