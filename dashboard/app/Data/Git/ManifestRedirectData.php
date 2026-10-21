<?php

declare(strict_types=1);

namespace App\Data\Git;

use Spatie\LaravelData\Data;

/**
 * The form the browser posts to GitHub to create the app (manifest flow).
 *
 * It travels to the next page view through the session, which is stored as
 * JSON (config/session.php `serialization`): an object comes back as a plain
 * array, so it goes in with {@see toSession()} and comes out with
 * {@see fromSession()}, never as an object.
 */
final class ManifestRedirectData extends Data
{
    public function __construct(
        public string $action,
        public string $manifest,
    ) {}

    /**
     * @return array{action: string, manifest: string}
     */
    public function toSession(): array
    {
        return ['action' => $this->action, 'manifest' => $this->manifest];
    }

    /** The flashed manifest form, or null for anything else. */
    public static function fromSession(mixed $value): ?self
    {
        if (! is_array($value) || ! is_string($value['action'] ?? null) || ! is_string($value['manifest'] ?? null)) {
            return null;
        }

        return new self($value['action'], $value['manifest']);
    }
}
