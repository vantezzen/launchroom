<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\ProtocolDocument;
use Spatie\LaravelData\Data;

/**
 * Signed response to an enrollment (protocol/schema/v1/enroll-response.json).
 */
final class EnrollResponse extends Data implements ProtocolDocument
{
    /**
     * @param  'active'|'pending_approval'  $status
     * @param  array<string, string>  $controlPlaneKeys  kid => "ed25519:<base64>"
     */
    public function __construct(
        public string $serverId,
        public string $status,
        public array $controlPlaneKeys,
        public RegistryInfo $registry,
        public PollIntervals $pollIntervals,
        public ?NudgeConfig $nudge = null,
    ) {}

    public function toProtocol(): array
    {
        return [
            'apiVersion' => self::API_VERSION,
            'kind' => 'EnrollResponse',
            'serverId' => $this->serverId,
            'status' => $this->status,
            'controlPlaneKeys' => (object) $this->controlPlaneKeys,
            'registry' => $this->registry->toProtocol(),
            'pollIntervals' => $this->pollIntervals->toProtocol(),
            ...($this->nudge instanceof NudgeConfig ? ['nudge' => $this->nudge->toProtocol()] : []),
        ];
    }
}
