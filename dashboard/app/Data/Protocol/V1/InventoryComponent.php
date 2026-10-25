<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use Spatie\LaravelData\Data;

/**
 * One normalised component of an image (InventoryResult.components,
 * docs/03 §13.1, docs/22 §3.4).
 */
final class InventoryComponent extends Data
{
    public const array ECOSYSTEMS = ['npm', 'pypi', 'go', 'maven', 'nuget', 'cargo', 'rubygems', 'packagist', 'hex', 'pub', 'deb', 'apk', 'rpm', 'binary'];

    public const array OS_ECOSYSTEMS = ['deb', 'apk', 'rpm'];

    public const array KINDS = ['os', 'library', 'runtime'];

    public const array SCOPES = ['runtime', 'dev', 'client', 'unknown'];

    public const array PRESENCES = ['installed', 'bundled'];

    public const string NAME = '/^[A-Za-z0-9@_][A-Za-z0-9@._\/:+~-]{0,255}$/D';

    public const string VERSION = '/^[A-Za-z0-9][A-Za-z0-9._+~:^-]{0,127}$/D';

    public const string PURL = '/^pkg:[a-z][a-z0-9.+-]{0,31}\/[!-~]{1,1000}$/D';

    public function __construct(
        public string $ecosystem,
        public string $name,
        public string $version,
        public ?string $purl,
        public string $kind,
        public string $scope,
        public string $presence,
        public bool $direct,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toProtocol(): array
    {
        return array_filter([
            'ecosystem' => $this->ecosystem,
            'name' => $this->name,
            'version' => $this->version,
            'purl' => $this->purl,
            'kind' => $this->kind,
            'scope' => $this->scope,
            'presence' => $this->presence,
            'direct' => $this->direct ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
