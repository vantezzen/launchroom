<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a staged change needs when deployed (docs/06 §4, docs/04 §7).
 * Declared on Data properties with #[App\Data\Attributes\ChangeAffects].
 */
enum ChangeEffect: string
{
    /** Takes effect with the revision, no container changes (watch paths, triggers). */
    case None = 'none';

    /** Only routing changes (domains): edge servers re-render, no deployment. */
    case Route = 'route';

    /** New deployment of the existing image (runtime config). */
    case Restart = 'restart';

    /** New deployment with a new build (build-affecting input). */
    case Rebuild = 'rebuild';

    /** A service new in this environment. */
    case New = 'new';

    /** A service removed from this environment. */
    case Remove = 'remove';

    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Route => 1,
            self::Restart => 2,
            self::Rebuild => 3,
            self::New, self::Remove => 4,
        };
    }

    /**
     * The stronger of two effects.
     */
    public function max(self $other): self
    {
        return $other->rank() > $this->rank() ? $other : $this;
    }

    /**
     * Whether deploying this effect creates a deployment.
     */
    public function needsDeployment(): bool
    {
        return in_array($this, [self::Restart, self::Rebuild, self::New], true);
    }

    /**
     * Services that are never built can't "rebuild": a build-affecting change
     * means a new deployment of a (re-resolved) image.
     */
    public function forKind(ServiceKind $kind): self
    {
        return $this === self::Rebuild && ! $kind->isBuilt() ? self::Restart : $this;
    }
}
