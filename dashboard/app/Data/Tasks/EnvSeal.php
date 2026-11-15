<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Data;

/**
 * The env a task gets sealed at lease time (`daemon_tasks.seal.env`), one of:
 * a deployment's env snapshot (only `keys` when given, plus the build summary
 * runtime defaults with `defaults`); `names` with empty values (plan
 * previews only need names); literal, non-secret `values` (the start check
 * of a generated Dockerfile: `PORT`, docs/20 §5).
 */
final class EnvSeal extends Data
{
    /**
     * @param  list<string>|null  $keys
     * @param  list<string>|null  $names
     * @param  array<string, string>|null  $values
     */
    public function __construct(
        public ?int $deployment = null,
        public ?array $keys = null,
        public bool $defaults = false,
        public ?array $names = null,
        public ?array $values = null,
    ) {}

    /**
     * @param  list<string>|null  $keys  null: all of them
     */
    public static function deployment(int $deploymentId, ?array $keys = null, bool $defaults = false): self
    {
        return new self(deployment: $deploymentId, keys: $keys, defaults: $defaults);
    }

    /**
     * @param  list<string>  $names
     */
    public static function names(array $names): self
    {
        return new self(names: $names);
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function values(array $values): self
    {
        return new self(values: $values);
    }
}
