<?php

declare(strict_types=1);

namespace App\Jobs\Domains;

use App\Actions\Domains\VerifyDomain;
use App\Models\Domain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Re-checks one custom domain's DNS off the request path (docs/05 §5).
 */
#[Timeout(30)]
#[Tries(1)]
#[UniqueFor(120)]
final class VerifyDomainJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $domainId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }

    public function handle(VerifyDomain $verify): void
    {
        $domain = Domain::query()->whereKey($this->domainId)->first();

        if ($domain instanceof Domain) {
            $verify->handle($domain);
        }
    }
}
