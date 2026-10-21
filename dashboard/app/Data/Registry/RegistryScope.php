<?php

declare(strict_types=1);

namespace App\Data\Registry;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

/**
 * One repository and the actions on it (protocol RegistryScope; Docker token
 * spec `repository:<name>:<actions>`).
 */
final class RegistryScope extends Data
{
    /** Repository grammar inside the launchroom registry (no host). */
    public const string REPOSITORY_PATTERN = '/^[a-z0-9]+(?:(?:\.|_|__|-+)[a-z0-9]+)*(?:\/[a-z0-9]+(?:(?:\.|_|__|-+)[a-z0-9]+)*)*$/D';

    public const string PULL = 'pull';

    public const string PUSH = 'push';

    /**
     * @param  list<string>  $actions
     */
    public function __construct(
        #[Max(255), Regex(self::REPOSITORY_PATTERN)]
        public string $repository,
        #[Min(1), Max(2)]
        public array $actions,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return ['actions.*' => ['string', 'distinct', 'in:pull,push']];
    }

    /**
     * Parses a Docker token-spec scope string; null for anything else
     * (registry:catalog:*, malformed names, unknown actions).
     */
    public static function fromScopeString(string $scope): ?self
    {
        $parts = explode(':', $scope);

        if (count($parts) !== 3 || $parts[0] !== 'repository' || strlen($parts[1]) > 255 || preg_match(self::REPOSITORY_PATTERN, $parts[1]) !== 1) {
            return null;
        }

        $actions = array_values(array_unique(array_filter(
            explode(',', $parts[2]),
            static fn (string $action): bool => in_array($action, [self::PULL, self::PUSH], true),
        )));

        return $actions === [] ? null : new self($parts[1], $actions);
    }

    /**
     * @return array{repository: string, actions: list<string>}
     */
    public function toProtocol(): array
    {
        return ['repository' => $this->repository, 'actions' => $this->actions];
    }
}
