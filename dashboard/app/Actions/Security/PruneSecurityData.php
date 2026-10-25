<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Enums\Security\SecurityFindingStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Retention of the security center (docs/22 §3.5, §4.3, §8), daily:
 *
 * - inventories no retained deployment uses any more (the last 10 per
 *   service, rollback targets included, docs/04 §8) and no finding points
 *   at;
 * - resolved findings and investigations after 90 days;
 * - advisory records no query matched for 30 days, and query rows no
 *   inventory needs.
 */
final readonly class PruneSecurityData
{
    /**
     * @return array{inventories: int, findings: int, investigations: int, advisories: int}
     */
    public function handle(): array
    {
        $retained = DB::table('deployments as d')
            ->whereNotNull('d.inventory_id')
            ->whereRaw('d.id IN (SELECT id FROM (SELECT id, row_number() OVER (PARTITION BY service_instance_id ORDER BY id DESC) AS n FROM deployments) ranked WHERE n <= 10)')
            ->select('d.inventory_id');

        $inventories = DB::table('image_inventories')
            ->whereNotIn('id', $retained)
            ->whereNotIn('id', DB::table('security_findings')->whereNotNull('inventory_id')->where('status', '!=', SecurityFindingStatus::Resolved->value)->select('inventory_id'))
            ->where('created_at', '<', now()->subDay())
            ->delete();

        $findings = DB::table('security_findings')
            ->where('status', SecurityFindingStatus::Resolved->value)
            ->where('resolved_at', '<', now()->subDays(config()->integer('launchroom.security.resolved_retention_days', 90)))
            ->delete();

        $investigations = DB::table('security_investigations')
            ->where('started_at', '<', now()->subDays(config()->integer('launchroom.security.investigation_retention_days', 90)))
            ->delete();

        $advisories = DB::table('advisories')
            ->where(static fn (Builder $q) => $q->where('matched_at', '<', now()->subDays(config()->integer('launchroom.security.advisory_retention_days', 30)))->orWhereNull('matched_at'))
            ->delete();

        DB::statement('DELETE FROM advisory_queries q WHERE NOT EXISTS (SELECT 1 FROM inventory_components c WHERE c.osv_ecosystem = q.osv_ecosystem AND c.osv_name = q.osv_name AND c.osv_version = q.version)');

        return ['inventories' => $inventories, 'findings' => $findings, 'investigations' => $investigations, 'advisories' => $advisories];
    }
}
