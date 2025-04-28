<?php

namespace App\Services\DeploymentTemplates;

class LaravelDeploymentTemplate extends DeploymentTemplate
{
    public function isResponsible(): bool
    {
        if (! file_exists($this->baseDirectory.'/composer.json') || ! file_exists($this->baseDirectory.'/artisan')) {
            return false;
        }

        $composer = json_decode(file_get_contents($this->baseDirectory.'/composer.json'), true);
        $containsLaravel = isset($composer['require']['laravel/framework']);

        return $containsLaravel;
    }

    public function addDeploymentFiles(): void
    {
        // TODO: Add env

        $migrateFile = <<<'MIGRATE'
#!/bin/sh
echo "💻 Migrating"
php "$APP_BASE_DIR/artisan" migrate --force

echo "🚀 Caching Laravel config..."
php "$APP_BASE_DIR/artisan" config:cache

echo "🚀 Caching Laravel routes..."
php "$APP_BASE_DIR/artisan" route:cache

echo "👋 App is ready!"
MIGRATE;
        file_put_contents($this->baseDirectory.'/_migrate.sh', $migrateFile);

        // Add Dockerfile
        $dockerfile = <<<'DOCKERFILE'
# syntax=docker.io/docker/dockerfile:1
FROM serversideup/php:8.4-fpm-nginx-alpine

ENV PHP_OPCACHE_ENABLE=1

WORKDIR /var/www/html

USER root
RUN apk add --no-cache coreutils grep sed curl git bash docker-cli nodejs-current npm

COPY --chmod=755 ./resources/docker/migrate.sh /etc/entrypoint.d/99-migrate.sh
COPY . .

RUN composer install --no-interaction --prefer-dist --optimize-autoloader
RUN mkdir -p /var/www/html/storage/framework/{sessions,views,cache} && chown -R www-data:www-data /var/www/html/storage
RUN mkdir -p /var/www/html/bootstrap/cache && chown -R www-data:www-data /var/www/html/bootstrap/cache
RUN npm install
RUN npm run build

USER www-data
EXPOSE 8080
DOCKERFILE;

        file_put_contents($this->baseDirectory.'/Dockerfile', $dockerfile);
    }

    public function getDockerComposeContents(): array
    {
        return [
            'services' => [
                'app' => [
                    'build' => '.',
                    'restart' => 'unless-stopped',
                ],
                'queue' => [
                    'build' => '.',
                    'restart' => 'unless-stopped',
                    'command' => 'php artisan queue:work --sleep=3 --tries=3 --timeout=90',
                ],
            ],
        ];
    }

    protected function getStaticEnvironmentVariables()
    {
        return [
            'APP_ENV' => 'production',
            'APP_URL' => $this->deployment->environment->domains[0],
        ];
    }
}
