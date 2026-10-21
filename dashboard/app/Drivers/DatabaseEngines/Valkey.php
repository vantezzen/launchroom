<?php

declare(strict_types=1);

namespace App\Drivers\DatabaseEngines;

use App\Enums\DatabaseEngine;

final readonly class Valkey extends RedisCompatible
{
    public function engine(): DatabaseEngine
    {
        return DatabaseEngine::Valkey;
    }

    public function defaultVersion(): string
    {
        return '8';
    }

    public function entrypoint(): array
    {
        // The image's ENTRYPOINT is `tini -- docker-entrypoint.sh`: tini as
        // PID 1 can't forward the stop signal to the valkey user (no
        // CAP_KILL), so the server would be killed instead of saving.
        return ['docker-entrypoint.sh'];
    }

    protected function binary(): string
    {
        return 'valkey-server';
    }
}
