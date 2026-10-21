<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DomainKind;
use App\Jobs\Domains\VerifyDomainJob;
use App\Models\Domain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Periodic DNS verification of custom domains (docs/05 §5): domains not yet
 * pointing at us are re-checked every few minutes, verified ones a few
 * times a day (records get changed or expire). At most 100 per run.
 */
#[Signature('launchroom:domains:verify')]
#[Description('Re-check the DNS of custom domains that are due')]
final class VerifyDomainsCommand extends Command
{
    public function handle(): int
    {
        $pendingBefore = now()->subMinutes(config()->integer('launchroom.dns.recheck_pending_minutes'))->toIso8601ZuluString();
        $verifiedBefore = now()->subHours(config()->integer('launchroom.dns.recheck_verified_hours'))->toIso8601ZuluString();

        $due = Domain::query()
            ->where('kind', DomainKind::Custom->value)
            ->where(static fn (Builder $query) => $query
                ->whereRaw("verification->>'checkedAt' is null")
                ->orWhere(static fn (Builder $query) => $query->whereRaw("verification->>'status' <> 'ok'")->whereRaw("verification->>'checkedAt' < ?", [$pendingBefore]))
                ->orWhere(static fn (Builder $query) => $query->whereRaw("verification->>'status' = 'ok'")->whereRaw("verification->>'checkedAt' < ?", [$verifiedBefore])))
            ->orderBy('id')
            ->limit(100)
            ->get(['id']);

        foreach ($due as $domain) {
            dispatch(new VerifyDomainJob($domain->id));
        }

        $this->info("Queued {$due->count()} domain checks.");

        return self::SUCCESS;
    }
}
