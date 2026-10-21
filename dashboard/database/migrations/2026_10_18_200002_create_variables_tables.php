<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Variables (docs/06 §3, docs/07 "Variables"). Values are AEAD ciphertexts
 * under the project DEK, associated data `team|project|variable|version`,
 * stored verbatim (docs/09 R13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variables', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Null: a project-level shared variable.
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete();
            // Set: owned by a canvas connection (deleted with it).
            $table->foreignId('connection_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 256);
            $table->text('value');
            // Bumped on every value change; part of the associated data.
            $table->unsignedInteger('version')->default(1);
            $table->string('type', 16);
            $table->string('exposure_override', 16)->nullable();
            $table->boolean('generated')->default(false);
            // References found in the value (`postgres.DATABASE_URL`, `shared.X`, `X`):
            // non-secret metadata for dependency analysis without decrypting.
            $table->jsonb('references')->default('[]');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'service_id']);
        });

        DB::statement("ALTER TABLE variables ADD CONSTRAINT variables_type_check CHECK (type IN ('config', 'secret'))");

        Schema::create('variable_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('all_previews')->default(false);
            $table->string('branch_pattern')->nullable();
            $table->timestamps();

            $table->index('environment_id');
        });

        DB::statement('ALTER TABLE variable_targets ADD CONSTRAINT variable_targets_one_kind CHECK ((environment_id IS NOT NULL) <> all_previews)');

        Schema::create('shared_variable_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['variable_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_variable_usages');
        Schema::dropIfExists('variable_targets');
        Schema::dropIfExists('variables');
    }
};
