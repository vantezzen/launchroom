<?php

namespace App\Services\DeploymentServices;

use App\Models\ProjectEnvironment;
use App\Models\Service;
use Illuminate\Support\Str;

class MySqlDeploymentService extends DeploymentService
{
    public function getDockerComposeContents(): array
    {
        return [
            'services' => [
                'mysql' => [
                    'image' => 'mysql:8.0',
                    'environment' => [
                        'MYSQL_ROOT_PASSWORD' => $this->service->environment_variables['DB_PASSWORD'],
                        'MYSQL_DATABASE' => $this->service->environment_variables['DB_DATABASE'],
                        'MYSQL_USER' => $this->service->environment_variables['DB_USERNAME'],
                        'MYSQL_PASSWORD' => $this->service->environment_variables['DB_PASSWORD'],
                    ],
                    'volumes' => [
                        'mysql-data:/var/lib/mysql',
                    ],
                    'networks' => [
                        'app',
                    ],
                ],
            ],
            'volumes' => [
                'mysql-data' => [],
            ],
        ];
    }

    public static function createServiceInEnvironment(ProjectEnvironment $environment, array $settings): Service
    {
        $password = Str::random(32);

        $service = $environment->services()->create([
            'name' => 'MySQL',
            'service_type' => 'mysql',
            'category' => 'database',
            'environment_variables' => [
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => 'mysql',
                'DB_PORT' => 3306,
                'DB_DATABASE' => 'app',
                'DB_USERNAME' => 'app',
                'DB_PASSWORD' => $password,
            ],
        ]);
        $service->save();

        return $service;
    }
}
