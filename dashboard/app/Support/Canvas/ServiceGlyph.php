<?php

declare(strict_types=1);

namespace App\Support\Canvas;

use App\Data\Services\DatabaseSource;
use App\Data\Services\GitSource;
use App\Data\Services\ImageSource;
use App\Enums\ServiceKind;
use App\Models\Service;
use App\Support\Images\ImageReference;

/**
 * The glyph key and one-line description of a service on the canvas.
 * Glyph keys are a closed vocabulary the UI maps to monochrome icons.
 */
final class ServiceGlyph
{
    /** Glyphs the UI knows; anything else falls back by kind. */
    public const array KNOWN = [
        'postgres', 'mysql', 'redis', 'valkey', 'mongodb', 'nginx', 'docker', 'github', 'gitlab', 'git',
        'nextjs', 'laravel', 'node', 'python', 'django', 'fastapi', 'flask', 'vite', 'static',
        'worker', 'cron', 'app',
        // One-click apps and stacks (templates and Compose imports, docs/17 §3.2).
        'umami', 'plausible', 'uptimekuma', 'n8n', 'ghost', 'gitea', 'forgejo', 'meilisearch', 'pocketbase',
        'metabase', 's3', 'clickhouse', 'mariadb', 'rails', 'ruby', 'php', 'celery', 'sidekiq', 'minio',
        'wordpress', 'grafana', 'nextcloud', 'mongodb',
    ];

    /** Image repository names (last path segment) with their own glyph. */
    private const array IMAGE_GLYPHS = [
        'postgres' => 'postgres',
        'mysql' => 'mysql',
        'mariadb' => 'mariadb',
        'redis' => 'redis',
        'valkey' => 'valkey',
        'mongo' => 'mongodb',
        'nginx' => 'nginx',
        'node' => 'node',
        'python' => 'python',
        'umami' => 'umami',
        'community-edition' => 'plausible',
        'uptime-kuma' => 'uptimekuma',
        'n8n' => 'n8n',
        'ghost' => 'ghost',
        'gitea' => 'gitea',
        'forgejo' => 'forgejo',
        'meilisearch' => 'meilisearch',
        'pocketbase' => 'pocketbase',
        'metabase' => 'metabase',
        'versitygw' => 's3',
        'clickhouse-server' => 'clickhouse',
        'minio' => 'minio',
        'wordpress' => 'wordpress',
        'grafana' => 'grafana',
        'nextcloud' => 'nextcloud',
        'postgresql' => 'postgres',
        'php' => 'php',
        'ruby' => 'ruby',
    ];

    public static function logo(Service $service, ?string $framework = null): string
    {
        if ($service->icon !== null && in_array($service->icon, self::KNOWN, true)) {
            return $service->icon;
        }

        $source = $service->source;

        return match ($service->kind) {
            ServiceKind::Database => $source instanceof DatabaseSource ? $source->engine->value : 'postgres',
            ServiceKind::Worker => 'worker',
            ServiceKind::Cron => 'cron',
            ServiceKind::Image => $source instanceof ImageSource ? self::imageGlyph($source->reference) : 'docker',
            ServiceKind::App => self::frameworkGlyph($framework) ?? ($source instanceof GitSource ? self::providerGlyph($source) : 'github'),
        };
    }

    public static function subtitle(Service $service, ?string $framework = null): string
    {
        $source = $service->source;

        return match (true) {
            $service->kind->requiresParent() => ($service->kind === ServiceKind::Cron ? 'Cron of ' : 'Worker of ').($service->parent->name ?? 'an app'),
            $source instanceof DatabaseSource => $source->engine->label().' '.$source->version,
            $source instanceof ImageSource => self::shortImage($source->reference),
            $source instanceof GitSource => $framework ?? $source->fullName,
            default => $service->kind->label(),
        };
    }

    public static function shortImage(string $reference): string
    {
        $parsed = ImageReference::tryParse($reference);

        if (! $parsed instanceof ImageReference) {
            return $reference;
        }

        $name = $parsed->repository;

        if (str_starts_with($name, 'library/')) {
            $name = substr($name, strlen('library/'));
        }

        return $name.($parsed->tag !== null ? ':'.$parsed->tag : ($parsed->digest !== null ? '@'.substr($parsed->digest, 7, 12) : ''));
    }

    /**
     * The glyph of an image reference (`ghcr.io/umami-software/umami:3` → `umami`).
     */
    public static function forImage(string $reference): string
    {
        return self::imageGlyph($reference);
    }

    private static function imageGlyph(string $reference): string
    {
        $parsed = ImageReference::tryParse($reference);
        $repository = $parsed instanceof ImageReference ? $parsed->repository : $reference;
        $last = basename($repository);

        return self::IMAGE_GLYPHS[$last] ?? 'docker';
    }

    /**
     * The git provider's mark for an app without a detected framework (docs/19).
     */
    private static function providerGlyph(GitSource $source): string
    {
        return match ($source->provider) {
            GitSource::PROVIDER_GITLAB => 'gitlab',
            GitSource::PROVIDER_GITEA => 'gitea',
            GitSource::PROVIDER_GIT => $source->isUnconnectedPlainGit() ? 'github' : 'git',
            default => 'github',
        };
    }

    private static function frameworkGlyph(?string $framework): ?string
    {
        if ($framework === null) {
            return null;
        }

        $key = strtolower($framework);

        return match (true) {
            str_contains($key, 'next') => 'nextjs',
            str_contains($key, 'laravel') => 'laravel',
            str_contains($key, 'django') => 'django',
            str_contains($key, 'fastapi') => 'fastapi',
            str_contains($key, 'flask') => 'flask',
            str_contains($key, 'vite') => 'vite',
            str_contains($key, 'static') => 'static',
            str_contains($key, 'python') => 'python',
            str_contains($key, 'node') || str_contains($key, 'express') => 'node',
            default => null,
        };
    }
}
