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
     * Stateful services keep data in a volume: never overlapped, never moved
     * automatically (docs/04 §8, docs/02 §6).
     */
    public function isStateful(): bool
    {
        return $this === self::Database;
    }

    /**
     * Workers/crons hang off an app service (docs/04 §1).
     */
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

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
