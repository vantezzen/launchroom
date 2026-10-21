<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generate a Dockerfile (docs/20, D48).
 *
 * `ai_provider_settings` is the instance's opt-in (singleton, id = 1): the
 * provider, model and API key. The key is envelope encrypted (docs/09 R13):
 * its own DEK wrapped by the KEK (`ai_provider:<provider>`), the value bound
 * to `ai_provider|<provider>`. Only the last four characters are kept in
 * clear for the settings page.
 *
 * `dockerfile_generations` is one run: read the repository, draft, lint,
 * build, start, repair (≤ N times). `context` (the scrubbed inspection sent
 * to the model) is encrypted at rest; `dockerfile` is immutable once
 * verified (build settings reference the row by public id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->string('provider', 32)->default('anthropic');
            $table->string('model', 64)->nullable();
            $table->text('api_key_ciphertext')->nullable();
            $table->text('wrapped_dek')->nullable();
            $table->string('kek_id', 64)->nullable();
            $table->string('api_key_last4', 4)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('key_set_at')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE ai_provider_settings ADD CONSTRAINT ai_provider_settings_singleton CHECK (id = 1)');

        Schema::create('dockerfile_generations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger', 32);
            $table->string('status', 32)->default('reading');
            $table->string('provider', 32);
            $table->string('model', 64);
            $table->string('commit_sha', 40)->nullable();
            $table->string('branch', 255)->nullable();
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedSmallInteger('max_attempts');
            // Tasks of the current step (plan, build, run_once).
            $table->foreignId('plan_task_id')->nullable()->constrained('daemon_tasks')->nullOnDelete();
            $table->foreignId('build_task_id')->nullable()->constrained('daemon_tasks')->nullOnDelete();
            $table->foreignId('run_task_id')->nullable()->constrained('daemon_tasks')->nullOnDelete();
            $table->text('context')->nullable();
            $table->jsonb('files_sent')->nullable();
            $table->jsonb('detected')->nullable();
            $table->jsonb('attempts')->nullable();
            $table->text('dockerfile')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('start_command', 4096)->nullable();
            $table->text('explanation')->nullable();
            $table->string('confidence', 16)->nullable();
            $table->string('image', 512)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('step_started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pull_request_url', 1024)->nullable();
            $table->timestamps();

            $table->index(['service_instance_id', 'id']);
            $table->index(['team_id', 'created_at']);
        });

        // One running generation per service instance.
        DB::statement("CREATE UNIQUE INDEX dockerfile_generations_one_running ON dockerfile_generations (service_instance_id) WHERE status IN ('reading', 'drafting', 'building', 'starting')");
        DB::statement("ALTER TABLE dockerfile_generations ADD CONSTRAINT dockerfile_generations_status CHECK (status IN ('reading', 'drafting', 'building', 'starting', 'verified', 'failed', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('dockerfile_generations');
        Schema::dropIfExists('ai_provider_settings');
    }
};
