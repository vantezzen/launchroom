<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\WireErrors;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;

/**
 * TaskCompletion.inventory (protocol/schema/v1/task-completion.json,
 * docs/03 §13.1): the packages and runtimes of one image. Read by hand
 * from the decoded body ({@see self::fromWire()}) with the same grammars
 * and cross-field rules as launchroomd; anything off refuses the result.
 */
final class InventoryResult extends Data
{
    public const int MAX_COMPONENTS = 20000;

    public const array LOCKFILE_KINDS = ['npm', 'pnpm', 'yarn', 'bun', 'composer', 'poetry', 'uv', 'pipfile', 'requirements', 'pyproject', 'gemfile', 'gomod'];

    public const string IMAGE = '#^(?:localhost|[a-z0-9-]+(?:\.[a-z0-9-]+)+|[a-z0-9-]+(?:\.[a-z0-9-]+)*:[0-9]{1,5})(?:/[a-z0-9]+(?:(?:\.|_|__|-+)[a-z0-9]+)*)+@sha256:[a-f0-9]{64}$#D';

    public const string REL_PATH = '#^[A-Za-z0-9._@+=,-]+(?:/[A-Za-z0-9._@+=,-]+)*$#D';

    /**
     * @param  array{id: string, versionId?: string}|null  $distro
     * @param  list<array{path: string, kind: string, packages: int}>  $lockfiles
     * @param  list<InventoryComponent>  $components
     * @param  list<string>  $notes
     */
    public function __construct(
        public string $image,
        public string $platform,
        public string $generator,
        public ?array $distro,
        public bool $sourceAnalyzed,
        public array $lockfiles,
        public array $components,
        public int $dropped,
        public bool $truncated,
        public array $notes,
        public int $durationMs,
    ) {}

    /**
     * @param  array<array-key, mixed>  $raw
     *
     * @throws ValidationException
     */
    public static function fromWire(array $raw): self
    {
        $e = new WireErrors;
        $p = 'inventory';

        $image = $e->string($raw['image'] ?? null, "{$p}.image", self::IMAGE, 512) ?? '';
        $platform = $e->enum($raw['platform'] ?? null, "{$p}.platform", ['linux/amd64', 'linux/arm64']) ?? '';
        $generator = $e->string($raw['generator'] ?? null, "{$p}.generator", self::IMAGE, 512) ?? '';
        $distro = null;

        if (isset($raw['distro'])) {
            $d = $e->object($raw['distro'], "{$p}.distro");
            $id = $e->string($d['id'] ?? null, "{$p}.distro.id", '/^[a-z0-9][a-z0-9._-]{0,31}$/D', 32);
            $versionId = $e->string($d['versionId'] ?? null, "{$p}.distro.versionId", '/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/D', 32, required: false);
            $distro = $id === null ? null : ['id' => $id, ...($versionId === null ? [] : ['versionId' => $versionId])];
        }

        $sourceAnalyzed = $e->bool($raw['sourceAnalyzed'] ?? null, "{$p}.sourceAnalyzed", required: true);
        $lockfiles = [];
        $paths = [];

        foreach ($e->list($raw['lockfiles'] ?? null, "{$p}.lockfiles", 32, required: false) as $i => $lockfile) {
            $q = "{$p}.lockfiles.{$i}";
            $l = $e->object($lockfile, $q);
            $path = $e->string($l['path'] ?? null, "{$q}.path", self::REL_PATH, 1024);

            if ($path !== null && array_intersect(explode('/', $path), ['.', '..']) !== []) {
                $e->add("{$q}.path", 'must not contain . or .. segments');
                $path = null;
            }
            $kind = $e->enum($l['kind'] ?? null, "{$q}.kind", self::LOCKFILE_KINDS);
            $packages = $e->int($l['packages'] ?? null, "{$q}.packages", 0, 100000);

            if ($path !== null && isset($paths[$path])) {
                $e->add("{$q}.path", 'is not unique');
            }

            if ($path !== null && $kind !== null) {
                $paths[$path] = true;
                $lockfiles[] = ['path' => $path, 'kind' => $kind, 'packages' => $packages];
            }
        }

        if ($lockfiles !== [] && ! $sourceAnalyzed) {
            $e->add("{$p}.lockfiles", 'are only valid when the source was analysed');
        }

        $components = [];
        $seen = [];

        foreach ($e->list($raw['components'] ?? null, "{$p}.components", self::MAX_COMPONENTS) as $i => $component) {
            $parsed = self::component($e, $component, "{$p}.components.{$i}");

            if (! $parsed instanceof InventoryComponent) {
                continue;
            }

            $key = $parsed->ecosystem."\0".$parsed->name."\0".$parsed->version;

            if (isset($seen[$key])) {
                $e->add("{$p}.components.{$i}", 'is not unique by ecosystem, name and version');

                continue;
            }

            $seen[$key] = true;
            $components[] = $parsed;
        }

        $truncated = $e->bool($raw['truncated'] ?? null, "{$p}.truncated");

        if ($truncated && count($components) < self::MAX_COMPONENTS) {
            $e->add("{$p}.truncated", 'is only valid with 20000 components');
        }

        $notes = [];

        foreach ($e->list($raw['notes'] ?? null, "{$p}.notes", 16, required: false) as $i => $note) {
            $text = $e->text($note, "{$p}.notes.{$i}", 256);

            if ($text !== null) {
                $notes[] = $text;
            }
        }

        $result = new self(
            $image,
            $platform,
            $generator,
            $distro,
            $sourceAnalyzed,
            $lockfiles,
            $components,
            $e->int($raw['dropped'] ?? null, "{$p}.dropped", required: false),
            $truncated,
            $notes,
            $e->int($raw['durationMs'] ?? null, "{$p}.durationMs"),
        );

        $e->throwIfAny();

        return $result;
    }

    private static function component(WireErrors $e, mixed $raw, string $q): ?InventoryComponent
    {
        $c = $e->object($raw, $q);
        $ecosystem = $e->enum($c['ecosystem'] ?? null, "{$q}.ecosystem", InventoryComponent::ECOSYSTEMS);
        $name = $e->string($c['name'] ?? null, "{$q}.name", InventoryComponent::NAME, 256);
        $version = $e->string($c['version'] ?? null, "{$q}.version", InventoryComponent::VERSION, 128);
        $purl = $e->string($c['purl'] ?? null, "{$q}.purl", InventoryComponent::PURL, 1024, required: false);
        $kind = $e->enum($c['kind'] ?? null, "{$q}.kind", InventoryComponent::KINDS);
        $scope = $e->enum($c['scope'] ?? null, "{$q}.scope", InventoryComponent::SCOPES);
        $presence = $e->enum($c['presence'] ?? null, "{$q}.presence", InventoryComponent::PRESENCES);
        $direct = $e->bool($c['direct'] ?? null, "{$q}.direct");

        if ($ecosystem === null || $name === null || $version === null || $kind === null || $scope === null || $presence === null) {
            return null;
        }

        $os = in_array($ecosystem, InventoryComponent::OS_ECOSYSTEMS, true);

        if ($kind === 'os' && ! $os) {
            $e->add("{$q}.kind", 'os is only valid for deb, apk and rpm packages');
        }

        if ($kind === 'library' && ($os || $ecosystem === 'binary')) {
            $e->add("{$q}.kind", 'library is only valid for language packages');
        }

        if ($presence === 'bundled' && $ecosystem !== 'npm') {
            $e->add("{$q}.presence", 'bundled is only valid for npm packages');
        }

        if (in_array($scope, ['dev', 'client'], true) && $kind !== 'library') {
            $e->add("{$q}.scope", 'dev and client are only valid for libraries');
        }

        if ($direct && $kind !== 'library') {
            $e->add("{$q}.direct", 'is only valid for libraries');
        }

        return new InventoryComponent($ecosystem, $name, $version, $purl, $kind, $scope, $presence, $direct);
    }

    /**
     * The exact wire shape (contract tests).
     *
     * @return array<string, mixed>
     */
    public function toProtocol(): array
    {
        return array_filter([
            'image' => $this->image,
            'platform' => $this->platform,
            'generator' => $this->generator,
            'distro' => $this->distro,
            'sourceAnalyzed' => $this->sourceAnalyzed,
            'lockfiles' => $this->lockfiles === [] ? null : $this->lockfiles,
            'components' => array_map(static fn (InventoryComponent $component): array => $component->toProtocol(), $this->components),
            'dropped' => $this->dropped === 0 ? null : $this->dropped,
            'truncated' => $this->truncated ?: null,
            'notes' => $this->notes === [] ? null : $this->notes,
            'durationMs' => $this->durationMs,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
