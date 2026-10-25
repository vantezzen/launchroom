<?php

declare(strict_types=1);

namespace App\Data\Protocol;

use App\Data\Protocol\V1\Report;
use Illuminate\Validation\ValidationException;

/**
 * Collects grammar errors while a large protocol document is read by hand
 * (inventories of up to 20,000 components would make Laravel's wildcard
 * validation slow). Mirrors launchroomd's `errs`: the first 20 problems are
 * reported, then the document is refused as a whole.
 */
final class WireErrors
{
    /** @var array<string, string> */
    private array $errors = [];

    public function add(string $path, string $message): void
    {
        if (count($this->errors) < 20) {
            $this->errors[$path] ??= $message;
        }
    }

    public function string(mixed $value, string $path, string $pattern, int $maxLength, bool $required = true): ?string
    {
        if ($value === null && ! $required) {
            return null;
        }

        if (! is_string($value) || strlen($value) > $maxLength || preg_match($pattern, $value) !== 1) {
            $this->add($path, 'is malformed');

            return null;
        }

        return $value;
    }

    /**
     * @param  list<string>  $allowed
     */
    public function enum(mixed $value, string $path, array $allowed): ?string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            $this->add($path, 'must be one of '.implode(', ', $allowed));

            return null;
        }

        return $value;
    }

    public function int(mixed $value, string $path, int $min = 0, int $max = PHP_INT_MAX, bool $required = true, int $default = 0): int
    {
        if ($value === null && ! $required) {
            return $default;
        }

        if (! is_int($value) || $value < $min || $value > $max) {
            $this->add($path, "must be an integer between {$min} and {$max}");

            return $default;
        }

        return $value;
    }

    public function bool(mixed $value, string $path, bool $required = false): bool
    {
        if ($value === null && ! $required) {
            return false;
        }

        if (! is_bool($value)) {
            $this->add($path, 'must be a boolean');

            return false;
        }

        return $value;
    }

    /**
     * Plain text: valid UTF-8 without control characters.
     */
    public function text(mixed $value, string $path, int $maxLength): ?string
    {
        if (! is_string($value) || strlen($value) > $maxLength || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            $this->add($path, 'must be plain text');

            return null;
        }

        return $value;
    }

    public function time(mixed $value, string $path, bool $required = true): ?string
    {
        if ($value === null && ! $required) {
            return null;
        }

        if (! is_string($value) || preg_match(Report::TIME_PATTERN, $value) !== 1) {
            $this->add($path, 'must be an RFC 3339 time');

            return null;
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    public function list(mixed $value, string $path, int $max, bool $required = true): array
    {
        if ($value === null && ! $required) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value) || count($value) > $max) {
            $this->add($path, "must be a list of at most {$max} items");

            return [];
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function object(mixed $value, string $path, bool $required = true): array
    {
        if ($value === null && ! $required) {
            return [];
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->add($path, 'must be an object');

            return [];
        }

        return $value;
    }

    /**
     * @throws ValidationException
     */
    public function throwIfAny(): void
    {
        if ($this->errors !== []) {
            throw ValidationException::withMessages($this->errors);
        }
    }
}
