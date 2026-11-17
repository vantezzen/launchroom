<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private registries (D91, docs/05 §6):
 *
 * - `registry_credentials`: a team's sign-in details for one upstream
 *   registry host. The password or token is envelope encrypted (docs/09
 *   R13), bound to `registry_credential|<team>|<public id>|secret`; it never
 *   leaves the dashboard (no NodeState, no task, no Zot config).
 * - `registry_image_copies`: one copy of a private image (by tag or digest)
 *   into launchroom's registry under `mirror/team_<id>/<host>/<repository>`,
 *   made by the dashboard at deploy time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registry_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('host', 253);
            $table->string('username', 255);
            $table->text('wrapped_dek');
            $table->string('kek_id', 64);
            $table->text('secret');
            $table->string('status', 16)->default('unverified');
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error', 1024)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['team_id', 'host']);
        });

        Schema::create('registry_image_copies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registry_credential_id')->nullable()->constrained('registry_credentials')->nullOnDelete();
            // `<host>/<repository>` as in the image reference.
            $table->string('source', 512);
            // The tag or digest that was asked for.
            $table->string('reference', 160);
            $table->string('digest', 71)->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('message', 2048)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'source', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registry_image_copies');
        Schema::dropIfExists('registry_credentials');
    }
};
