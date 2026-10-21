<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tamper-evident audit log (D50, docs/09 R34): signed checkpoints of the
 * hash chain, forwarding sinks with their delivery cursor, and the last
 * verification result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_checkpoints', function (Blueprint $table): void {
            $table->id();
            // No foreign key: a deleted event must not take its checkpoint along.
            $table->unsignedBigInteger('event_id')->index();
            $table->char('event_hash', 64);
            $table->unsignedBigInteger('event_count');
            $table->string('instance_id', 40)->nullable();
            $table->string('kid', 64);
            $table->string('signature', 88);
            $table->timestampTz('created_at', 6);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_checkpoints_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_checkpoints is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_checkpoints_no_update_delete
                BEFORE UPDATE OR DELETE ON audit_checkpoints
                FOR EACH ROW EXECUTE FUNCTION audit_checkpoints_append_only();
            SQL);

        Schema::create('audit_sinks', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 32)->unique();
            $table->string('kind', 16);
            $table->string('name', 80);
            // Non-secret settings (syslog host/port/CA, S3 endpoint/bucket/…).
            $table->jsonb('config')->default('{}');
            $table->string('target_hint', 255)->nullable();
            $table->text('wrapped_dek')->nullable();
            $table->string('kek_id', 64)->nullable();
            $table->text('secret')->nullable();
            $table->boolean('allow_private_network')->default(false);
            $table->boolean('enabled')->default(true);
            // Last audit event id delivered (0 = from the first entry).
            $table->unsignedBigInteger('cursor_event_id')->default(0);
            $table->timestampTz('delivered_through_at', 6)->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unsignedInteger('failures')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();
        });

        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->jsonb('audit_verification')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->dropColumn('audit_verification');
        });
        Schema::dropIfExists('audit_sinks');
        Schema::dropIfExists('audit_checkpoints');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_checkpoints_append_only()');
    }
};
