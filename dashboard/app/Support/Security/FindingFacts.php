<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Enums\Security\Severity;

/**
 * The inputs of the alert-level rules for one finding (docs/22 §5.1).
 */
final readonly class FindingFacts
{
    public function __construct(
        public string $ecosystem,
        public string $name,
        /** `os`, `library` or `runtime`. */
        public string $kind,
        /** `runtime`, `dev`, `client` or `unknown`. */
        public string $scope,
        /** `installed` or `bundled`. */
        public string $presence,
        /** The image was built by launchroom (else pulled from a registry). */
        public bool $builtImage,
        public bool $preview,
        /** A domain route without preview protection. */
        public bool $public,
        public Severity $severity,
        public ?float $cvssScore,
        public bool $fixed,
        /** N, A, L, P or null without a CVSS vector. */
        public ?string $attackVector,
        public bool $knownExploited,
        public ?string $fixedVersion = null,
        public ?string $domain = null,
        public ?string $lockfile = null,
    ) {}

    /**
     * OS packages and binaries: judged by the exposed list (docs/22 §5.3).
     */
    public function isSystemLevel(): bool
    {
        return in_array($this->ecosystem, ['deb', 'apk', 'rpm', 'binary'], true)
            || ($this->ecosystem === 'go' && $this->name === 'stdlib');
    }
}
