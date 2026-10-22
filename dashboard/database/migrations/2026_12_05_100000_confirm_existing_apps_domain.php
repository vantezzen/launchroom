<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The address step of Get started is done once the owner continued after
 * the DNS check (docs/08 §3.1), not when the domain is saved. A platform
 * domain set before that keeps its step done (`verified: null`), so owners
 * who finished the checklist aren't sent back to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('instance_settings')->whereNotNull('apps_domain')->get(['id', 'apps_domain', 'onboarding']) as $row) {
            $onboarding = is_string($row->onboarding) ? json_decode($row->onboarding, true) : null;
            $onboarding = is_array($onboarding) ? $onboarding : [];

            if (isset($onboarding['domainConfirmed'])) {
                continue;
            }

            $onboarding['domainConfirmed'] = ['domain' => $row->apps_domain, 'verified' => null, 'at' => now()->toIso8601ZuluString()];

            DB::table('instance_settings')->where('id', $row->id)->update(['onboarding' => json_encode($onboarding, JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        // Data only: the extra key is ignored by older code.
    }
};
