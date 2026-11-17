<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a server, its edge and its jobs went through, for the server page
 * and the alert rules (docs/03 §9, docs/15 §3.4):
 *
 * - `servers.last_rejection`: the dashboard refused a request this server
 *   signed correctly (clock skew, revoked key, client certificate),
 *   recorded at most once a minute (App\Http\Middleware\AuthenticateDaemon);
 * - `servers.last_daemon_exit`: launchroomd's last unclean exit, crash or
 *   host reboot (Report.host.daemon), kept after the report that carried it;
 * - `servers.control_plane_health`: Report.controlPlane of the control-plane
 *   server (watchdog), with what it restarted;
 * - `domains.cert_failure_kind`/`cert_retry_after`: why the last certificate
 *   attempt failed and when the certificate authority allows the next one;
 * - `alert_channels.fallback`: the one channel launchroomd itself notifies
 *   while the dashboard is down (OD7);
 * - `cron_runs`: each run of a scheduled cron service (start, end, exit);
 * - `service_crashes`: exits with an error of live replicas (not by launchroom).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->jsonb('last_rejection')->nullable();
            $table->jsonb('last_daemon_exit')->nullable();
            $table->jsonb('control_plane_health')->nullable();
        });

        Schema::table('domains', function (Blueprint $table): void {
            $table->string('cert_failure_kind', 32)->nullable();
            $table->timestamp('cert_retry_after')->nullable();
        });

        Schema::table('alert_channels', function (Blueprint $table): void {
            $table->boolean('fallback')->default(false);
            $table->boolean('allow_private_hosts')->default(false);
        });

        Schema::create('cron_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('workload_id', 40);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->boolean('oom_killed')->default(false);
            $table->timestamps();
            $table->index(['service_instance_id', 'started_at']);
            $table->index(['workload_id', 'finished_at']);
        });

        Schema::create('service_crashes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deployment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('workload_id', 40);
            $table->timestamp('at');
            $table->integer('exit_code')->nullable();
            $table->unsignedSmallInteger('signal')->nullable();
            $table->boolean('oom_killed')->default(false);
            $table->string('code', 32)->nullable();
            // A re-sent event is stored once (launchroomd's seq restarts with it).
            $table->unique(['workload_id', 'at']);
            $table->index(['service_instance_id', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_crashes');
        Schema::dropIfExists('cron_runs');

        Schema::table('alert_channels', function (Blueprint $table): void {
            $table->dropColumn(['fallback', 'allow_private_hosts']);
        });

        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn(['cert_failure_kind', 'cert_retry_after']);
        });

        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn(['last_rejection', 'last_daemon_exit', 'control_plane_health']);
        });
    }
};
