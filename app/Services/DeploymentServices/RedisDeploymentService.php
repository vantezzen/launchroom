<?php

namespace App\Services\DeploymentServices;

use App\Models\Environment;
use App\Models\Service;
use Illuminate\Support\Str;

class RedisDeploymentService extends DeploymentService
{
    public function getDockerComposeContents(): array
    {
        return [
            'services' => [
                'redis' => [
                    'image' => 'redis:alpine',
                    'volumes' => [
                        'redis_data:/data',
                    ],
                    'networks' => [
                        'app',
                    ],
                ],
            ],
            'volumes' => [
                'redis_data' => [],
            ],
        ];
    }

    public function getCriticalServiceNames(): array
    {
        return ['redis'];
    }

    public static function createServiceInEnvironment(Environment $environment, array $settings): Service
    {
        $password = Str::random(32);

        $service = $environment->services()->create([
            'name' => 'Redis',
            'service_type' => 'redis',
            'category' => 'cache',
            'environment_variables' => [
                'REDIS_HOST' => 'redis',
                'REDIS_PORT' => 6379,
                'CACHE_STORE' => 'redis',
                'QUEUE_CONNECTION' => 'redis',
            ],
        ]);
        $service->save();

        return $service;
    }
}
