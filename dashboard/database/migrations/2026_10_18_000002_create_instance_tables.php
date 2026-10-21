<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/07 "Instance-level": exactly one row, enforced by a check constraint.
        Schema::create('instance_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            // First-run setup token (docs/09 R10): SHA-256 hex, single use.
            $table->char('setup_token_hash', 64)->nullable();
            $table->timestamp('setup_token_expires_at')->nullable();
            $table->timestamp('setup_completed_at')->nullable();
            $table->timestamp('recovery_kit_confirmed_at')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE instance_settings ADD CONSTRAINT instance_settings_singleton CHECK (id = 1)');

        Schema::create('signing_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('kid', 64)->unique();
            // "ed25519:<base64>" — the private key lives in a file (LAUNCHROOM_SIGNING_KEY_PATH).
            $table->string('public_key', 64)->unique();
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            // No foreign keys: audit events outlive teams, users and servers.
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 64)->index();
            $table->string('subject_type', 32)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->jsonb('meta')->default('{}');
            $table->char('prev_hash', 64);
            $table->char('hash', 64)->unique();
            $table->timestampTz('created_at', 6);
        });

        // Append-only at the database level too: no UPDATE or DELETE.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_events_no_update_delete
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_events_append_only()');
        Schema::dropIfExists('signing_keys');
        Schema::dropIfExists('instance_settings');
    }
};
