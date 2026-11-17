<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Service kinds (docs/04 §1).
 */
enum ServiceKind: string
{
    /** Built from a git repository. */
    case App = 'app';

    /** A prebuilt image (mirrored, never built). */
    case Image = 'image';

    /** An engine driver (Postgres, MySQL, Redis/Valkey). */
    case Database = 'database';

    /** Same image as its parent app, different command. */
    case Worker = 'worker';

    /** Same image as its parent app, a schedule and a command. */
    case Cron = 'cron';

    /**
     * Whether deployments of this kind need an image build (or the parent's build).
     */
    public function isBuilt(): bool
    {
        return in_array($this, [self::App, self::Worker, self::Cron], true);
    }

    /**
     * Databases keep data in their engine's volume: never overlapped, never
     * moved automatically (docs/04 §8, docs/02 §6). Other services with a
     * volume stop before they start too, unless they allow two copies
     * (ServiceInstance::stopsBeforeStart), and are pinned to their volume's
     * server (ServiceInstance::isStateful).
     */
    public function isStateful(): bool
    {
        return $this === self::Database;
    }

    public function requiresParent(): bool
    {
        return in_array($this, [self::Worker, self::Cron], true);
    }

    /**
     * Whether the kind serves HTTP and gets generated domains when it has a port.
     */
    public function isWeb(): bool
    {
        return in_array($this, [self::App, self::Image], true);
    }

    /**
     * Has volumes of its own (Settings → Volumes), which it can share with
     * other services (D73). A database's volume belongs to its engine.
     */
    public function ownsVolumes(): bool
    {
        return in_array($this, [self::App, self::Image], true);
    }

    /**
     * May mount another service's volume: it runs code that reads files (D73).
     */
    public function canMountSharedVolume(): bool
    {
        return $this !== self::Database;
    }

    /**
     * Deploys new versions of its own source; workers and crons follow their
     * app, databases are restarted.
     */
    public function deploysNewVersions(): bool
    {
        return in_array($this, [self::App, self::Image], true);
    }

    /** The source a service of this kind has; null: it runs its parent's image. */
    public function sourceType(): ?ServiceSourceType
    {
        return match ($this) {
            self::App => ServiceSourceType::Git,
            self::Image => ServiceSourceType::Image,
            self::Database => ServiceSourceType::Database,
            self::Worker, self::Cron => null,
        };
    }

    public function sourceLabel(): string
    {
        return match ($this) {
            self::App => 'git repository',
            self::Image => 'image reference',
            self::Database => 'database engine',
            self::Worker, self::Cron => 'parent app',
        };
    }

    /**
     * What a config file may set for this kind besides kind, name, enabled,
     * scaling and variables (docs/18 §3.2).
     *
     * @return list<string>
     */
    public function configFields(): array
    {
        return match ($this) {
            self::App => ['rootDirectory', 'branch', 'build', 'deploy', 'domains', 'volumes', 'connections'],
            self::Image => ['image', 'deploy', 'domains', 'volumes', 'connections'],
            self::Database => ['engine', 'version'],
            self::Worker, self::Cron => ['of', 'branch', 'deploy', 'connections'],
        };
    }

    public function hasConfigField(string $field): bool
    {
        return in_array($field, $this->configFields(), true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
