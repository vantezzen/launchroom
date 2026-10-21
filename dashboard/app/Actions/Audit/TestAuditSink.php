<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Exceptions\DomainRuleViolation;
use App\Models\AuditSink;
use App\Services\Audit\AuditDeliveryFailed;
use App\Services\Audit\AuditForwarder;
use App\Services\Crypto\Exceptions\DecryptionFailed;

/**
 * "Send test" (docs/09 R34): one harmless record to the destination, with
 * the same checks as real deliveries. S3 sinks also learn whether the
 * bucket has Object Lock. Returns what to tell the user; a failure is a
 * user-facing rule violation with the safe message.
 */
final readonly class TestAuditSink
{
    public function __construct(
        private AuditForwarder $forwarder,
    ) {}

    public function handle(AuditSink $sink): string
    {
        try {
            return $this->forwarder->transport($sink->kind)->test($sink);
        } catch (AuditDeliveryFailed $e) {
            throw DomainRuleViolation::on('general', $e->getMessage());
        } catch (DecryptionFailed) {
            throw DomainRuleViolation::on('general', 'The stored credentials could not be opened. Remove this destination and add it again.');
        }
    }
}
