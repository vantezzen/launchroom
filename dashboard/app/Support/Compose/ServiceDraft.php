<?php

declare(strict_types=1);

namespace App\Support\Compose;

use App\Data\Blueprints\BlueprintBuild;
use App\Data\Blueprints\BlueprintConnection;
use App\Data\Blueprints\BlueprintDatabase;
use App\Data\Blueprints\BlueprintNote;
use App\Data\Blueprints\BlueprintScaling;
use App\Data\Blueprints\BlueprintService;
use App\Data\Blueprints\BlueprintVariable;
use App\Data\Services\HealthcheckSettings;
use App\Enums\BlueprintNoteKind;
use App\Enums\ConnectionStyle;
use App\Enums\DatabaseEngine;
use App\Enums\ServiceKind;

/**
 * One Compose service while ComposeTranslator works on it (mutable, internal).
 */
final class ServiceDraft
{
    public ServiceKind $kind = ServiceKind::Image;

    public ?string $logo = null;

    public ?string $image = null;

    public ?BlueprintBuild $build = null;

    public ?BlueprintDatabase $database = null;

    /** A well-known database image (managed or kept as a container). */
    public ?DatabaseEngine $databaseImage = null;

    /** Becomes a managed database (rather than a plain container). */
    public bool $managed = false;

    public ?string $parent = null;

    public ?int $port = null;

    public bool $public = false;

    public ?bool $publicOverride = null;

    public ?string $domain = null;

    /** @var list<string>|null */
    public ?array $entrypoint = null;

    /** @var list<string>|null */
    public ?array $command = null;

    public ?HealthcheckSettings $healthcheck = null;

    public BlueprintScaling $scaling;

    /** @var list<string> */
    public array $capabilities = [];

    /** @var list<string> */
    public array $volumes = [];

    public int $bindMounts = 0;

    /** @var array<string, array{raw: string, value: string, resolved: string, secret: bool, line: int|null}> */
    public array $env = [];

    /** @var array<string, string> raw environment of the source (databases: credentials) */
    public array $rawEnv = [];

    /** @var array<string, string> resolved environment of the source */
    public array $resolvedEnv = [];

    /** @var list<string> Compose service names from depends_on */
    public array $dependsOn = [];

    /** @var array<string, ConnectionStyle> blueprint key => style */
    public array $connections = [];

    /** @var array<string, ConnectionStyle> template overrides: blueprint key => style */
    public array $connectStyles = [];

    /** @var list<string> host names other services may use for it */
    public array $aliases = [];

    /** @var list<BlueprintNote> */
    public array $notes = [];

    /** @var list<string>|null raw command words (redis `--requirepass`) */
    public ?array $rawCommand = null;

    /**
     * @param  array<array-key, mixed>  $definition
     */
    public function __construct(
        public readonly string $composeName,
        public string $key,
        public string $name,
        public readonly array $definition,
    ) {
        $this->scaling = new BlueprintScaling;
    }

    /** Returns null so it can stand in a `match` arm. */
    public function note(BlueprintNoteKind $kind, string $message, string $field, ?int $line): null
    {
        $this->notes[] = new BlueprintNote($kind, $message, $field, $line);

        return null;
    }

    public function toBlueprint(): BlueprintService
    {
        $notes = $this->notes;
        usort($notes, static fn (BlueprintNote $a, BlueprintNote $b): int => [self::rank($a->kind), $a->line ?? PHP_INT_MAX] <=> [self::rank($b->kind), $b->line ?? PHP_INT_MAX]);

        return new BlueprintService(
            key: $this->key,
            name: $this->name,
            kind: $this->kind,
            logo: $this->logo,
            image: $this->kind === ServiceKind::Image ? $this->image : null,
            build: $this->kind === ServiceKind::App ? $this->build : null,
            database: $this->kind === ServiceKind::Database ? $this->database : null,
            parent: $this->kind === ServiceKind::Worker ? $this->parent : null,
            port: in_array($this->kind, [ServiceKind::App, ServiceKind::Image], true) ? $this->port : null,
            public: in_array($this->kind, [ServiceKind::App, ServiceKind::Image], true) && ($this->publicOverride ?? $this->public),
            domain: $this->domain,
            entrypoint: $this->kind === ServiceKind::Database ? null : $this->entrypoint,
            startCommand: $this->kind === ServiceKind::Database ? null : $this->command,
            healthcheck: in_array($this->kind, [ServiceKind::App, ServiceKind::Image], true) ? $this->healthcheck : null,
            scaling: $this->scaling->isEmpty() || $this->kind === ServiceKind::Database ? null : $this->scaling,
            capabilities: $this->kind === ServiceKind::Database ? [] : $this->capabilities,
            volumes: in_array($this->kind, [ServiceKind::App, ServiceKind::Image], true) ? $this->volumes : [],
            variables: $this->kind === ServiceKind::Database ? [] : array_map(
                static fn (string $key, array $entry): BlueprintVariable => new BlueprintVariable($key, $entry['value'], $entry['secret']),
                array_keys($this->env),
                $this->env,
            ),
            connections: array_map(
                static fn (string $to, ConnectionStyle $style): BlueprintConnection => new BlueprintConnection($to, $style),
                array_keys($this->connections),
                $this->connections,
            ),
            notes: $notes,
        );
    }

    private static function rank(BlueprintNoteKind $kind): int
    {
        return match ($kind) {
            BlueprintNoteKind::Refused => 0,
            BlueprintNoteKind::Warning => 1,
            BlueprintNoteKind::Translated => 2,
            BlueprintNoteKind::Ignored => 3,
        };
    }
}
