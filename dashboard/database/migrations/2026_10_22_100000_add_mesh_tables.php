<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mesh (docs/05 §2):
 *
 * - instance_settings.mesh_*: whether the mesh is on and how (`embedded`
 *   Headscale in the control-plane stack, or `byo` "bring your own
 *   Tailscale" with its login server), the Headscale user the dashboard
 *   registers servers under, the hash of the last applied ACL policy and the
 *   last error of enabling/maintaining it (shown to the instance owner).
 * - servers.mesh_*: the mesh address(es) and Headscale node id of a server,
 *   and the latest mesh status from its reports (whitelisted).
 * - mesh_credentials: envelope-encrypted secrets of the mesh (docs/09 R13):
 *   the Headscale API key, the "bring your own Tailscale" auth key, and per
 *   server the single-use pre-auth key until the server has joined. One DEK
 *   per row, wrapped by the KEK (rotated by `launchroom:keys:rotate-kek`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->string('mesh_mode', 16)->nullable();
            $table->timestamp('mesh_enabled_at')->nullable();
            $table->string('mesh_login_server', 512)->nullable();
            $table->string('mesh_headscale_user', 32)->nullable();
            $table->string('mesh_policy_hash', 64)->nullable();
            $table->string('mesh_error', 1000)->nullable();
            $table->timestamp('mesh_error_at')->nullable();
        });

        Schema::table('servers', function (Blueprint $table): void {
            $table->string('mesh_ipv6', 45)->nullable();
            $table->string('mesh_node_id', 32)->nullable();
            $table->jsonb('mesh_status')->nullable();
            $table->timestamp('mesh_status_at')->nullable();
        });

        Schema::create('mesh_credentials', function (Blueprint $table): void {
            $table->id();
            // `instance` or `server:<servers.id>`.
            $table->string('scope', 64);
            $table->string('purpose', 32);
            $table->foreignId('server_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('ciphertext');
            $table->text('wrapped_dek');
            $table->string('kek_id', 64);
            // Headscale's id of the credential (pre-auth key id, API key prefix).
            $table->string('external_id', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mesh_credentials');

        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn(['mesh_ipv6', 'mesh_node_id', 'mesh_status', 'mesh_status_at']);
        });

        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->dropColumn(['mesh_mode', 'mesh_enabled_at', 'mesh_login_server', 'mesh_headscale_user', 'mesh_policy_hash', 'mesh_error', 'mesh_error_at']);
        });
    }
};
