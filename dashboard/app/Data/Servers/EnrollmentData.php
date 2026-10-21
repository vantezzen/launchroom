<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\ServerRole;
use App\Models\ServerEnrollment;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * State of an "Add server" flow, polled by the waiting screen.
 */
final class EnrollmentData extends Data
{
    /**
     * @param  list<ServerRole>  $roles
     */
    public function __construct(
        public string $id,
        /** The name picked in "Add server" (optional). */
        public ?string $name,
        public array $roles,
        public CarbonInterface $expiresAt,
        public bool $expired,
        public ?ServerData $server,
    ) {}

    public static function fromEnrollment(ServerEnrollment $enrollment): self
    {
        return new self(
            $enrollment->public_id,
            $enrollment->name,
            array_values($enrollment->roles->all()),
            $enrollment->expires_at,
            $enrollment->used_at === null && ! $enrollment->expires_at->isFuture(),
            $enrollment->server !== null ? ServerData::fromServer($enrollment->server) : null,
        );
    }
}
