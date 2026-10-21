<?php

declare(strict_types=1);

/*
 * Waits until the application's default database connection accepts queries.
 *
 * Boots the Laravel console kernel so it honours exactly the same config as the
 * app (DB_URL, DB_HOST, …) instead of re-implementing connection parsing.
 *
 * Usage: php wait-for-db.php [timeout-seconds]   (run from the app root)
 */

$timeout = (int) ($argv[1] ?? 60);
$deadline = time() + $timeout;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = $app->make('db');
$lastError = '';

while (true) {
    try {
        $db->connection()->select('select 1');
        fwrite(STDOUT, "[launchroom] database is reachable\n");
        exit(0);
    } catch (Throwable $e) {
        $lastError = $e->getMessage();
        $db->purge();
    }

    if (time() >= $deadline) {
        // The message comes from the driver and does not include the password.
        fwrite(STDERR, "[launchroom] database not reachable after {$timeout}s: {$lastError}\n");
        exit(1);
    }

    sleep(2);
}
