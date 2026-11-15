<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\ReplacedServiceKind;
use App\Enums\ReplacedServiceStatus;
use Spatie\LaravelData\Data;

/**
 * One service's row in the "Replace server" progress (docs/14 §5.3).
 */
final class ReplacedServiceProgressData extends Data
{
    /**
     * @param  array{snapshot: string, takenAt: string|null}|null  $restore
     */
    public function __construct(
        public string $instance,
        public string $name,
        public ReplacedServiceKind $kind,
        public ?array $restore,
        public ReplacedServiceStatus $status,
        public ?string $message,
    ) {}

    public static function fromService(ReplacedServiceData $service): self
    {
        return new self($service->instance, $service->name, $service->kind, $service->restore, $service->status, $service->message);
    }
}
