<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Config as code (docs/18 §8, D45): the last read of each environment's
 * launchroom.json (what it manages, its problems), and the file errors of a
 * deployment that failed on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environment_config_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('path', 1024);
            $table->string('repository', 255)->nullable();
            $table->string('commit_sha', 64)->nullable();
            $table->string('status', 16);
            $table->jsonb('managed')->default('{}');
            $table->jsonb('errors')->default('[]');
            $table->jsonb('warnings')->default('[]');
            $table->jsonb('ignored')->default('[]');
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('checked_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE environment_config_files ADD CONSTRAINT environment_config_files_status_check CHECK (status IN ('applied', 'staged', 'unchanged', 'invalid', 'missing'))");

        Schema::table('deployments', function (Blueprint $table): void {
            $table->jsonb('config_errors')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropColumn('config_errors');
        });

        Schema::dropIfExists('environment_config_files');
    }
};
