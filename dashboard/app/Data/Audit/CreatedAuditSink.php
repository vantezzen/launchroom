<?php

declare(strict_types=1);

namespace App\Data\Audit;

use App\Models\AuditSink;

/**
 * A new sink and, for webhooks, its signing secret (shown once).
 */
final readonly class CreatedAuditSink
{
    public function __construct(
        public AuditSink $sink,
        #[\SensitiveParameter]
        public ?string $signingSecret,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['sink' => $this->sink->public_id, 'signingSecret' => '[redacted]'];
    }
}
