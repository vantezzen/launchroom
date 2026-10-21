<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alerts and notifications (docs/15 §3, docs/07 "Alerts"):
 *
 * - alert_channels: where a team hears about alerts (email, Slack, Discord,
 *   signed webhook). Webhook URLs and signing secrets are envelope encrypted
 *   with a per-channel data key (docs/09 R13); only a display hint is clear.
 * - alert_rule_settings: a team's overrides of the built-in rules (on/off,
 *   threshold, duration); missing rows mean the rule's defaults.
 * - alerts: one open problem per (team, rule, subject); a partial unique
 *   index keeps at most one firing row per key (dedup by construction).
 * - alert_deliveries: one row per message to one channel, its outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 30)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('name', 80);
            $table->jsonb('recipients')->nullable();
            $table->string('target_hint', 120)->nullable();
            $table->text('secret')->nullable();
            $table->text('wrapped_dek')->nullable();
            $table->string('kek_id', 64)->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('min_severity', 16)->default('warning');
            $table->timestampTz('last_delivery_at')->nullable();
            $table->boolean('last_delivery_ok')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['team_id', 'id']);
        });

        Schema::create('alert_rule_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('rule', 32);
            $table->boolean('enabled');
            $table->float('threshold', 24)->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->timestampsTz();
            $table->unique(['team_id', 'rule']);
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 30)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('rule', 32);
            $table->string('subject_key', 120);
            $table->string('severity', 16);
            $table->string('status', 16);
            $table->string('title', 200);
            $table->text('summary');
            $table->string('href', 500)->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('facts')->default('{}');
            $table->string('occurrence', 64)->nullable();
            $table->integer('occurrences')->default(1);
            $table->timestampTz('opened_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolve_reason', 300)->nullable();
            $table->timestampTz('notified_at')->nullable();
            $table->timestampsTz();
            $table->index(['team_id', 'opened_at']);
            $table->index(['team_id', 'rule', 'subject_key', 'resolved_at']);
            $table->index(['status', 'rule']);
        });

        DB::statement("CREATE UNIQUE INDEX alerts_one_firing_per_subject ON alerts (team_id, rule, subject_key) WHERE status = 'firing'");
        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_status_check CHECK (status IN ('firing', 'resolved'))");
        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_severity_check CHECK (severity IN ('warning', 'critical'))");
        DB::statement("ALTER TABLE alert_channels ADD CONSTRAINT alert_channels_kind_check CHECK (kind IN ('email', 'slack', 'discord', 'webhook'))");

        Schema::create('alert_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 30)->unique();
            $table->foreignId('alert_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('alert_channels')->cascadeOnDelete();
            $table->string('event', 16);
            $table->string('status', 16);
            $table->smallInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->index(['channel_id', 'created_at']);
            $table->index(['alert_id', 'event']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('alert_rule_settings');
        Schema::dropIfExists('alert_channels');
    }
};
