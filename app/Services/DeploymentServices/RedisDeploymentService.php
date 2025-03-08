<?php

namespace App\Services\DeploymentServices;

use App\Models\ProjectEnvironment;
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
                    'environment' => [
                        'REDIS_PASSWORD' => $this->service->environment_variables['REDIS_PASSWORD'],
                    ],
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

    public static function createServiceInEnvironment(ProjectEnvironment $environment, array $settings): Service
    {
        $password = Str::random(32);

        $service = $environment->services()->create([
            'name' => 'Redis',
            'service_type' => 'redis',
            'category' => 'cache',
            'environment_variables' => [
                'REDIS_HOST' => 'redis',
                'REDIS_PORT' => 6379,
                'REDIS_PASSWORD' => $password,
                'CACHE_STORE' => 'redis',
                'QUEUE_CONNECTION' => 'redis',
            ],
        ]);
        $service->save();

        return $service;
    }
}
