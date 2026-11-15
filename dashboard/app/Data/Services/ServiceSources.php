<?php

declare(strict_types=1);

namespace App\Data\Services;

use App\Enums\ServiceSourceType;
use App\Exceptions\DomainRuleViolation;

/**
 * The stored shape of the ServiceSource union: its fields plus the `type`
 * discriminator. The one place that reads and writes it.
 */
final class ServiceSources
{
    /**
     * @param  array<mixed>  $data  a stored source
     */
    public static function fromArray(array $data): ServiceSource
    {
        $type = ServiceSourceType::from(is_string($data['type'] ?? null) ? $data['type'] : '');
        unset($data['type']);

        return $type->sourceClass()::from($data);
    }

    /**
     * Like fromArray(), validating the fields (input from outside).
     *
     * @param  array<mixed>  $data
     *
     * @throws DomainRuleViolation for an unknown type
     */
    public static function validated(array $data): ServiceSource
    {
        $type = ServiceSourceType::tryFrom(is_string($data['type'] ?? null) ? $data['type'] : '')
            ?? throw DomainRuleViolation::on('source', 'Unknown source type.');
        unset($data['type']);

        return $type->sourceClass()::validateAndCreate($data);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(ServiceSource $source): array
    {
        return ['type' => $source->type()->value, ...$source->toArray()];
    }
}
