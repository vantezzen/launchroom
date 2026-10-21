<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Services\GitHub\Exceptions\InvalidGitHubPayload;
use Carbon\CarbonImmutable;
use JsonException;
use Throwable;

/**
 * Typed, read-only access to a decoded GitHub JSON object. Every accessor
 * validates the type, so payloads never flow on as `mixed` arrays.
 */
final readonly class JsonObject
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public function __construct(
        private array $data,
    ) {}

    /**
     * @throws InvalidGitHubPayload
     */
    public static function decode(string $json): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidGitHubPayload::because('the body is not valid JSON');
        }

        if (! is_array($data) || array_is_list($data) && $data !== []) {
            throw InvalidGitHubPayload::because('the body is not a JSON object');
        }

        return new self($data);
    }

    /**
     * A top-level JSON array of objects (list endpoints).
     *
     * @return list<self>
     *
     * @throws InvalidGitHubPayload
     */
    public static function decodeList(string $json): array
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidGitHubPayload::because('the body is not valid JSON');
        }

        if (! is_array($data) || ! array_is_list($data)) {
            throw InvalidGitHubPayload::because('the body is not a JSON array');
        }

        return new self(['items' => $data])->objects('items');
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data) && $this->data[$key] !== null;
    }

    public function int(string $key): int
    {
        $value = $this->data[$key] ?? null;

        if (! is_int($value)) {
            throw InvalidGitHubPayload::field($key, 'an integer');
        }

        return $value;
    }

    public function intOrNull(string $key): ?int
    {
        return $this->has($key) ? $this->int($key) : null;
    }

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;

        if (! is_string($value)) {
            throw InvalidGitHubPayload::field($key, 'a string');
        }

        return $value;
    }

    public function stringOrNull(string $key): ?string
    {
        return $this->has($key) ? $this->string($key) : null;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->data[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * A timestamp given as an ISO 8601 string (REST API) or Unix seconds
     * (some webhook payloads, e.g. `repository.pushed_at` on push).
     */
    public function timestampOrNull(string $key): ?CarbonImmutable
    {
        $value = $this->data[$key] ?? null;

        if (is_int($value)) {
            return CarbonImmutable::createFromTimestampUTC($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw InvalidGitHubPayload::field($key, 'a timestamp');
        }
    }

    public function object(string $key): self
    {
        $value = $this->data[$key] ?? null;

        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            throw InvalidGitHubPayload::field($key, 'an object');
        }

        return new self($value);
    }

    public function objectOrNull(string $key): ?self
    {
        return $this->has($key) ? $this->object($key) : null;
    }

    /**
     * @return list<self>
     */
    public function objects(string $key): array
    {
        $value = $this->data[$key] ?? [];

        if (! is_array($value)) {
            throw InvalidGitHubPayload::field($key, 'a list');
        }

        $objects = [];

        foreach ($value as $item) {
            if (! is_array($item)) {
                throw InvalidGitHubPayload::field($key, 'a list of objects');
            }

            $objects[] = new self($item);
        }

        return $objects;
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $value = $this->data[$key] ?? [];

        if (! is_array($value)) {
            throw InvalidGitHubPayload::field($key, 'a list');
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
