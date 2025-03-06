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
        $dockerfile = <<<DOCKERFILE
# syntax=docker.io/docker/dockerfile:1
FROM serversideup/php:8.3-fpm-nginx

ENV PHP_OPCACHE_ENABLE=1

WORKDIR /var/www/html

USER root

RUN apt-get update && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

ENV NODE_VERSION 22.13.1
RUN apt install -y curl
RUN curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash
ENV NVM_DIR=/root/.nvm
RUN . "\$NVM_DIR/nvm.sh" && nvm install \${NODE_VERSION}
RUN . "\$NVM_DIR/nvm.sh" && nvm use v\${NODE_VERSION}
RUN . "\$NVM_DIR/nvm.sh" && nvm alias default v\${NODE_VERSION}
ENV PATH="/root/.nvm/versions/node/v\${NODE_VERSION}/bin/:\${PATH}"

COPY --chmod=755 ./_migrate.sh /etc/entrypoint.d/99-migrate.sh
COPY . .

RUN mkdir -p /var/www/html/storage/framework/{sessions,views,cache} && chmod -R 777 /var/www/html/storage
RUN mkdir -p /var/www/html/bootstrap/cache && chmod -R 777 /var/www/html/bootstrap/cache
RUN npm install
RUN npm run build

USER www-data

RUN composer install --optimize-autoloader

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
}
