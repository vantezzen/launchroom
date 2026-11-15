<?php

declare(strict_types=1);

namespace App\Drivers\DatabaseEngines;

use App\Enums\DatabaseEngine;

final readonly class Redis extends RedisCompatible
{
    public function engine(): DatabaseEngine
    {
        return DatabaseEngine::Redis;
    }

    protected function binary(): string
    {
        return 'redis-server';
    }
}
