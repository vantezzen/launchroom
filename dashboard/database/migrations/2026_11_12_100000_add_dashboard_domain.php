<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard at its own domain (docs/05 §4.1, D43):
 *
 * - instance_settings: the canonical origin chosen in Settings → Dashboard
 *   domain (null = APP_URL), the earlier origins that keep answering
 *   (`[{url, since, until}]`), the host routed while a new domain is being
 *   verified, and the passkey relying party id in use.
 * - dashboard_domain_changes: one row per attempt (DNS, certificate and HTTPS
 *   test results), at most one open (pending/ready) and one active.
 * - servers.daemon_control_plane: which dashboard URL launchroomd uses
 *   (Report.host.controlPlane), so the move of every server is visible.
 * - passkeys.rp_id: the relying party a passkey was registered for (existing
 *   ones: the install-time host), so passkeys of an earlier address can be
 *   told apart after a switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->string('dashboard_url', 512)->nullable();
            $table->jsonb('dashboard_legacy_urls')->nullable();
            $table->string('dashboard_pending_host', 253)->nullable();
            $table->string('passkey_rp_id', 253)->nullable();
            $table->timestamp('dashboard_switched_at')->nullable();
        });

        Schema::create('dashboard_domain_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('hostname', 253);
            $table->string('status', 16)->default('pending');
            $table->string('previous_url', 512);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('dns')->nullable();
            $table->jsonb('certificate')->nullable();
            $table->jsonb('probe')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('switched_at')->nullable();
            $table->foreignId('switched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 16)->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        DB::statement("ALTER TABLE dashboard_domain_changes ADD CONSTRAINT dashboard_domain_changes_status_check CHECK (status IN ('pending', 'ready', 'active', 'cancelled', 'reverted'))");
        DB::statement("CREATE UNIQUE INDEX dashboard_domain_changes_one_open ON dashboard_domain_changes ((true)) WHERE status IN ('pending', 'ready')");
        DB::statement("CREATE UNIQUE INDEX dashboard_domain_changes_one_active ON dashboard_domain_changes ((true)) WHERE status = 'active'");

        Schema::table('servers', function (Blueprint $table): void {
            $table->jsonb('daemon_control_plane')->nullable();
        });

        Schema::table('passkeys', function (Blueprint $table): void {
            $table->string('rp_id', 253)->nullable();
        });

        $host = parse_url(config()->string('app.url'), PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            DB::table('passkeys')->whereNull('rp_id')->update(['rp_id' => strtolower($host)]);
        }
    }

    public function down(): void
    {
        Schema::table('passkeys', function (Blueprint $table): void {
            $table->dropColumn('rp_id');
        });

        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('daemon_control_plane');
        });

        Schema::dropIfExists('dashboard_domain_changes');

        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->dropColumn(['dashboard_url', 'dashboard_legacy_urls', 'dashboard_pending_host', 'passkey_rp_id', 'dashboard_switched_at']);
        });
    }
};
