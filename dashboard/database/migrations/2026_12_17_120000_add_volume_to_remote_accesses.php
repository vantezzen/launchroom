<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Volume access (D67, docs/03 §12.4): "Connect with SFTP" into one volume
 * is a temporary access of kind `volume`, with the volume it serves and the
 * owner launchroomd's helper reads and writes as.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_accesses', function (Blueprint $table): void {
            $table->foreignId('volume_id')->nullable()->after('service_instance_id')->constrained()->nullOnDelete();
            $table->jsonb('volume_owner')->nullable()->after('database_name');
        });
        DB::statement('ALTER TABLE remote_accesses DROP CONSTRAINT remote_accesses_kind_check');
        DB::statement("ALTER TABLE remote_accesses ADD CONSTRAINT remote_accesses_kind_check CHECK (kind IN ('ssh', 'database', 'volume'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM remote_accesses WHERE kind = 'volume'");
        DB::statement('ALTER TABLE remote_accesses DROP CONSTRAINT remote_accesses_kind_check');
        DB::statement("ALTER TABLE remote_accesses ADD CONSTRAINT remote_accesses_kind_check CHECK (kind IN ('ssh', 'database'))");
        Schema::table('remote_accesses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('volume_id');
            $table->dropColumn('volume_owner');
        });
    }
};
