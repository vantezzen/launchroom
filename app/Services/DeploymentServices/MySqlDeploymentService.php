<?php

namespace App\Services\DeploymentServices;

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
}
