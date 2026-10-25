<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staged volume removal (docs/14 §9.12): `removed_at` marks a volume that
 * leaves the service with the next deploy. Its row stays while a deployment
 * that mounts it runs, then it is retired like the volumes of a deleted
 * environment (docs/05 §1.1). A mount path is unique among the volumes that
 * are not removed, so a new volume can take the path of a removed one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volumes', function (Blueprint $table): void {
            $table->timestamp('removed_at')->nullable();
            $table->dropUnique(['service_instance_id', 'mount_path']);
        });

        DB::statement('CREATE UNIQUE INDEX volumes_instance_mount_path_current_unique ON volumes (service_instance_id, mount_path) WHERE removed_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS volumes_instance_mount_path_current_unique');
        DB::table('volumes')->whereNotNull('removed_at')->delete();

        Schema::table('volumes', function (Blueprint $table): void {
            $table->dropColumn('removed_at');
            $table->unique(['service_instance_id', 'mount_path']);
        });
    }
};
