<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zot on S3 (docs/14 §9.11, docs/05 §6): the registry's storage on an
 * S3-compatible bucket instead of the control-plane disk. A singleton row
 * (id = 1); no row means local storage. The S3 credentials are envelope
 * encrypted (docs/09 R13): a DEK wrapped by the KEK, the value bound to
 * `registry_storage|instance|credentials`.
 *
 * `config_hash` / `config_written_at` record the last Zot config written by
 * `launchroom:registry:config --write` (sha256 of the file), so the settings
 * page can say whether the saved settings are applied yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registry_storage', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->string('endpoint', 512);
            $table->string('bucket', 63);
            $table->string('region', 32)->nullable();
            $table->string('prefix', 256)->default('registry');
            $table->boolean('force_path_style')->default(true);
            // Instance-owner-only, audited override for LAN/mesh endpoints and plain http (docs/09 R5).
            $table->boolean('allow_private_network')->default(false);
            $table->text('wrapped_dek');
            $table->string('kek_id', 64);
            $table->text('credentials');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE registry_storage ADD CONSTRAINT registry_storage_singleton CHECK (id = 1)');

        Schema::table('instance_settings', function (Blueprint $table): void {
            // Last Zot config written to disk (any storage), see the class comment.
            $table->string('registry_config_hash', 64)->nullable();
            $table->timestamp('registry_config_written_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->dropColumn(['registry_config_hash', 'registry_config_written_at']);
        });
        Schema::dropIfExists('registry_storage');
    }
};
