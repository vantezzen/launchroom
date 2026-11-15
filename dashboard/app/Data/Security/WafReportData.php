<?php

declare(strict_types=1);

namespace App\Data\Security;

use App\Data\Protocol\V1\WafStatus;
use App\Enums\Security\WafEdgeState;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * `servers.waf_status`: the edge's latest Report.ingress.waf and since when
 * its state holds (App\Actions\Security\RecordWafReport).
 */
final class WafReportData extends Data
{
    public function __construct(
        public WafEdgeState $state,
        public string $since,
        public int $routes,
        public string $reportedAt,
        public ?string $engine = null,
        public ?string $ruleSet = null,
        public ?int $droppedRecords = null,
        public ?string $message = null,
    ) {}

    public static function fromProtocol(WafStatus $waf, ?self $previous, string $now): self
    {
        $state = WafEdgeState::from($waf->state);

        return new self(
            state: $state,
            since: $previous instanceof self && $previous->state === $state ? $previous->since : $now,
            routes: $waf->routes,
            reportedAt: $now,
            engine: $waf->engine instanceof Optional ? null : $waf->engine,
            ruleSet: $waf->ruleSet instanceof Optional ? null : $waf->ruleSet,
            droppedRecords: $waf->droppedRecords instanceof Optional ? null : $waf->droppedRecords,
            message: $waf->message instanceof Optional ? null : mb_substr($waf->message, 0, 1024),
        );
    }
}
