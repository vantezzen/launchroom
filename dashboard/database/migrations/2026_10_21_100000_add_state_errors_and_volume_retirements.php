<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integration hardening:
 *
 * - servers.render_error: the dashboard could not render a valid NodeState
 *   for this server (App\Services\NodeStates\NodeStateValidator); the
 *   previous version stays published. {message, problems, deployments, at}.
 * - servers.state_rejection: the daemon refused a NodeState it fetched
 *   (Report.stateRejected) and keeps running its last good one.
 * - volume_retirements: volumes of deleted environments, projects and
 *   previews, kept for a retention period and then deleted on their server
 *   through an explicit NodeState.deleteVolumes request (docs/05 §1.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->jsonb('render_error')->nullable();
            $table->jsonb('state_rejection')->nullable();
        });

        Schema::create('volume_retirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            // The volume row is gone with its instance; its identity lives on here.
            $table->string('volume_public_id', 40)->unique();
            $table->string('docker_name', 64);
            $table->string('service_public_id', 40);
            $table->string('environment_public_id', 40);
            $table->string('label', 255);
            $table->string('reason', 32);
            $table->string('mount_path', 1024);
            $table->timestamp('retain_until');
            $table->boolean('backups_configured')->default(false);
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('retained');
            $table->timestamp('requested_at')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'status']);
            $table->index(['status', 'retain_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volume_retirements');

        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn(['render_error', 'state_rejection']);
        });
    }
};
