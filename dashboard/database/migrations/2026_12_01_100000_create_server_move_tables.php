<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move to a new server (D56, docs/14 §13):
 *
 * - server_moves: one planned move of everything on a server (source) to
 *   another (target) while the source is online; `control_plane` when it is
 *   the workload half of a control-plane move;
 * - server_move_items: one per service instance that lived on the source,
 *   with its own status (stateless: redeployed; dump / files: stopped,
 *   copied through the dashboard with D55's export and import tasks,
 *   started on the target);
 * - control_plane_moves: Settings → Move launchroom: the one-time move token
 *   (hash only), the new machine's claim (its X25519 public key and the
 *   comparison code), its enrollment, the hand-over (freeze, final dump
 *   sealed to the new machine) and the result;
 * - instance_settings: the write freeze during a hand-over, where the
 *   instance moved to, and the checklist the new dashboard shows after it;
 * - backup_runs.reason `pre_move` (the optional fresh backup before a move).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_moves', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('target_server_id')->constrained('servers')->cascadeOnDelete();
            $table->boolean('control_plane')->default(false);
            $table->string('status', 16);
            // {backupFirst: bool}
            $table->jsonb('options')->default('{}');
            // What the plan said when it started: platforms, DNS records, estimates.
            $table->jsonb('plan')->default('{}');
            // The target got the edge role for this move (the source was the only edge).
            $table->boolean('edge_added')->default(false);
            $table->string('error', 2048)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });
        DB::statement("ALTER TABLE server_moves ADD CONSTRAINT server_moves_status_check CHECK (status IN ('running', 'ready', 'cancelling', 'completed', 'cancelled'))");
        // One open move per source server; a server that is a move's target can't be moved away meanwhile (checked by StartServerMove).
        DB::statement("CREATE UNIQUE INDEX server_moves_one_per_source ON server_moves (source_server_id) WHERE status IN ('running', 'ready', 'cancelling')");

        Schema::create('server_move_items', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_move_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained('service_instances')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedSmallInteger('position');
            $table->string('status', 24);
            // The step that failed (the item is retried from there).
            $table->string('failed_step', 24)->nullable();
            $table->string('message', 2048)->nullable();
            // Bytes the plan expected to copy (latest backup size), null when unknown.
            $table->unsignedBigInteger('expected_bytes')->nullable();
            // Bytes copied (sum of the exports).
            $table->unsignedBigInteger('copied_bytes')->nullable();
            $table->foreignId('deployment_id')->nullable()->constrained('deployments')->nullOnDelete();
            $table->foreignId('prebuild_deployment_id')->nullable()->constrained('deployments')->nullOnDelete();
            $table->foreignId('backup_run_id')->nullable()->constrained('backup_runs')->nullOnDelete();
            // Per volume: {volume: vol_…, export: exp_…|null, import: imp_…|null}.
            $table->jsonb('transfers')->default('[]');
            // The target's desired-state version that declares the copied volumes.
            $table->unsignedBigInteger('target_version')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('cutover_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['server_move_id', 'position']);
            $table->index(['service_instance_id', 'status']);
        });
        DB::statement("ALTER TABLE server_move_items ADD CONSTRAINT server_move_items_kind_check CHECK (kind IN ('stateless', 'dump', 'files'))");
        DB::statement("ALTER TABLE server_move_items ADD CONSTRAINT server_move_items_status_check CHECK (status IN ('pending', 'building', 'backing_up', 'stopping', 'exporting', 'preparing', 'importing', 'starting', 'done', 'failed', 'rolling_back', 'rolled_back', 'cancelled', 'skipped'))");

        Schema::create('control_plane_moves', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->string('status', 24);
            $table->foreignId('source_server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->foreignId('target_server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->foreignId('server_move_id')->nullable()->constrained('server_moves')->nullOnDelete();
            // SHA-256 of the token's secret; the token is shown once.
            $table->string('token_hash', 64);
            $table->timestamp('token_expires_at');
            // The new machine: X25519 public key (base64), the code both sides show, what it said about itself.
            $table->string('receiver_public_key', 64)->nullable();
            $table->string('claim_code', 16)->nullable();
            $table->jsonb('receiver')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('receiver_seen_at')->nullable();
            $table->foreignId('enrollment_id')->nullable()->constrained('server_enrollments')->nullOnDelete();
            // The new machine's join token, sealed to its key (crypto_box_seal); hidden.
            $table->text('sealed_join_token')->nullable();
            // The hand-over: chunk count and size, digests, the sealed data key and key bundle; hidden.
            $table->jsonb('handover')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('handed_over_at')->nullable();
            // The address the new dashboard reported when it took over.
            $table->string('new_url', 255)->nullable();
            $table->string('error', 2048)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE control_plane_moves ADD CONSTRAINT control_plane_moves_status_check CHECK (status IN ('waiting_for_server', 'claimed', 'joining', 'joined', 'moving', 'ready_to_hand_over', 'handing_over', 'handover_ready', 'handed_over', 'cancelled'))");
        DB::statement("CREATE UNIQUE INDEX control_plane_moves_one_open ON control_plane_moves ((true)) WHERE status NOT IN ('handed_over', 'cancelled')");

        Schema::table('instance_settings', function (Blueprint $table): void {
            // Writes refused while the control plane is handed over (docs/14 §13.4).
            $table->timestamp('frozen_at')->nullable();
            $table->string('frozen_reason', 255)->nullable();
            // The address this instance moved to; set once the new dashboard took over.
            $table->string('moved_to', 255)->nullable();
            // The new dashboard's checklist after a move: {startedAt, moveId, previousServerId, previousUrl}.
            $table->jsonb('move_checklist')->nullable();
        });

        DB::statement('ALTER TABLE backup_runs DROP CONSTRAINT IF EXISTS backup_runs_reason_check');
        DB::statement("ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_reason_check CHECK (reason IN ('scheduled', 'manual', 'pre_upgrade', 'pre_restore', 'pre_delete', 'pre_deploy', 'pre_move'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE backup_runs SET reason = 'manual' WHERE reason = 'pre_move'");
        DB::statement('ALTER TABLE backup_runs DROP CONSTRAINT IF EXISTS backup_runs_reason_check');
        DB::statement("ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_reason_check CHECK (reason IN ('scheduled', 'manual', 'pre_upgrade', 'pre_restore', 'pre_delete', 'pre_deploy'))");

        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->dropColumn(['frozen_at', 'frozen_reason', 'moved_to', 'move_checklist']);
        });
        Schema::dropIfExists('control_plane_moves');
        Schema::dropIfExists('server_move_items');
        Schema::dropIfExists('server_moves');
    }
};
