<?php

declare(strict_types=1);

namespace App\Support\Images;

/**
 * A built image: `<registry>/apps/<service id lowercased>@sha256:<digest>`
 * (docs/04 §5). One repository per service; launchroomd may only push to
 * its own service's repository.
 */
final readonly class AppImage
{
    private function __construct(
        /** The lowercased service public id. */
        public string $service,
        public string $digest,
    ) {}

    /** The image name builds push to (no digest): `127.0.0.1:5000/apps/svc_…`. */
    public static function nameFor(string $servicePublicId): string
    {
        return self::prefix().strtolower($servicePublicId);
    }

    /** Whether $image is in launchroom's apps repository at all. */
    public static function isBuilt(string $image): bool
    {
        return str_starts_with($image, self::prefix());
    }

    /** Null unless $image is a digest-pinned built image. */
    public static function parse(string $image): ?self
    {
        if (preg_match('/^'.preg_quote(self::prefix(), '/').'([a-z0-9_]+)@('.ImageReference::DIGEST_BODY.')$/D', $image, $m) !== 1) {
            return null;
        }

        return new self($m[1], $m[2]);
    }

    public function belongsTo(string $servicePublicId): bool
    {
        return $this->service === strtolower($servicePublicId);
    }

    /** The repository inside the registry (`apps/svc_…`), as registry tokens scope it. */
    public function repository(): string
    {
        return RegistryLayout::APPS.'/'.$this->service;
    }

    private static function prefix(): string
    {
        return RegistryLayout::appsPrefix();
    }
}
