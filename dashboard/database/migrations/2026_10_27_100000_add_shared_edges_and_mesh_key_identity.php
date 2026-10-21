<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Findings of the 2026-10 security review (docs/09 R14, R25):
 *
 * - servers.shared_edge_approved_at/_by (M2): an instance owner admitted this
 *   edge to the install-wide certificate storage and DNS-01 credentials
 *   (the control plane needs no admission);
 * - servers.mesh_auth_key_ids (M4): the Headscale pre-auth key ids issued to
 *   this server, the identity its Headscale node is linked by (never the
 *   mesh address the server reports).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->timestamp('shared_edge_approved_at')->nullable();
            $table->foreignId('shared_edge_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('mesh_auth_key_ids')->default('[]');
        });

        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->text('recovery_mac_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shared_edge_approved_by');
            $table->dropColumn(['shared_edge_approved_at', 'mesh_auth_key_ids']);
        });

        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->dropColumn('recovery_mac_key');
        });
    }
};
