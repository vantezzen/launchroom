<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * JSON columns are cast to Data objects (docs/10 §2.2 rules 2 and 6): stored
 * shapes the casts can't read are converted here instead of being skipped by
 * every reader.
 *
 * - domains.verification: null until the first DNS check (was `{}`).
 * - DNS checks without the keys DnsCheckData needs are dropped (the next
 *   check writes them again).
 * - instance_settings.audit_verification: the shape AuditVerificationData
 *   reads (problem message and entry instead of a nested problem).
 * - Plan previews without a known status and build summaries without a
 *   known builder are dropped (the next plan writes them again).
 *
 * The shapes and values are frozen here as they were when this migration
 * was written: it must not change with the app classes it prepares for,
 * and it never needs the KEK.
 */
return new class extends Migration
{
    private const array PLAN_STATUSES = ['planning', 'ready', 'failed'];

    private const array BUILDERS = ['dockerfile', 'railpack'];

    private const array BUILDER_REASONS = ['explicit', 'dockerfile_detected', 'preset', 'railpack'];

    private const string DNS_CHECK_COMPLETE = "jsonb_exists_all(%s, array['status', 'hostname', 'addresses', 'expected', 'message', 'checkedAt'])
        AND %s->>'status' IN ('ok', 'proxied', 'mismatch', 'missing', 'unknown', 'error')";

    public function up(): void
    {
        Schema::table('domains', static function (Blueprint $table): void {
            $table->jsonb('verification')->nullable()->default(null)->change();
        });

        DB::update('UPDATE domains SET verification = NULL WHERE verification IS NOT NULL AND NOT ('.sprintf(self::DNS_CHECK_COMPLETE, 'verification', 'verification').')');
        DB::update('UPDATE dashboard_domain_changes SET dns = NULL WHERE dns IS NOT NULL AND NOT ('.sprintf(self::DNS_CHECK_COMPLETE, 'dns', 'dns').')');

        $this->auditVerification();
        $this->buildSummaries('service_instances');
        $this->buildSummaries('deployments');
    }

    public function down(): void
    {
        DB::update("UPDATE domains SET verification = '{}'::jsonb WHERE verification IS NULL");

        Schema::table('domains', static function (Blueprint $table): void {
            $table->jsonb('verification')->default('{}')->nullable(false)->change();
        });
    }

    private function auditVerification(): void
    {
        $stored = DB::table('instance_settings')->whereNotNull('audit_verification')->value('audit_verification');
        $status = is_string($stored) ? json_decode($stored, true) : null;

        if (! is_array($status) || array_key_exists('problemEntry', $status)) {
            return;
        }

        $problem = $status['problem'] ?? null;
        $converted = is_string($status['checkedAt'] ?? null) ? [
            'ok' => ($status['ok'] ?? false) === true,
            'mode' => ($status['mode'] ?? null) === 'incremental' ? 'incremental' : 'full',
            'checkedAt' => $status['checkedAt'],
            'entries' => is_int($status['entries'] ?? null) ? $status['entries'] : 0,
            'verifiedUpTo' => is_string($status['verifiedUpTo'] ?? null) ? $status['verifiedUpTo'] : null,
            'problem' => is_array($problem) && is_string($problem['message'] ?? null) ? $problem['message'] : null,
            'problemEntry' => is_array($problem) && is_int($problem['eventId'] ?? null) ? $problem['eventId'] : null,
        ] : null;

        DB::table('instance_settings')->update(['audit_verification' => $converted === null ? null : json_encode($converted, JSON_THROW_ON_ERROR)]);
    }

    private function buildSummaries(string $table): void
    {
        DB::table($table)->whereNotNull('plan')->select(['id', 'plan'])->orderBy('id')->chunkById(500, static function (Collection $rows) use ($table): void {
            foreach ($rows as $row) {
                $plan = is_string($row->plan) ? json_decode($row->plan, true) : null;

                if ($table === 'service_instances' && (! is_array($plan) || ! in_array($plan['status'] ?? null, self::PLAN_STATUSES, true))) {
                    DB::table($table)->where('id', $row->id)->update(['plan' => null]);

                    continue;
                }

                $summary = is_array($plan) ? ($plan['summary'] ?? null) : null;

                if (! is_array($plan) || ! is_array($summary)) {
                    continue;
                }

                if (! in_array($summary['builder'] ?? null, self::BUILDERS, true) || ! in_array($summary['builderReason'] ?? null, self::BUILDER_REASONS, true)) {
                    $plan['summary'] = null;
                    DB::table($table)->where('id', $row->id)->update(['plan' => json_encode($plan, JSON_THROW_ON_ERROR)]);
                }
            }
        });
    }
};
