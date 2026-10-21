<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-edge ingress (docs/05 §4–5):
 *
 * - instance_settings.dns_*: the DNS provider for DNS-01 wildcard
 *   certificates of the platform domain and the result of its last token
 *   check (no secrets).
 * - ingress_credentials: envelope-encrypted ingress secrets (docs/09 R13):
 *   the DNS provider token and the shared certificate storage's encryption
 *   key. One DEK per row, wrapped by the KEK (`launchroom:keys:rotate-kek`).
 * - domains.cert_*: the certificate state edges report (issuer, validity,
 *   last error, which server reported it); domains.hsts: Strict-Transport-
 *   Security for a custom domain once its certificate is issued (opt-out).
 * - servers.ingress_status: the latest Report.ingress summary (route count,
 *   certificate storage state), whitelisted.
 * - placements.mesh_port: the dashboard-allocated mesh-published port of a
 *   workload (31000–32999, docs/05 §3). Allocated by the Placer;
 *   edges route to `<server mesh ip>:<mesh_port>` only when set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->string('dns_provider', 16)->nullable();
            $table->timestamp('dns_verified_at')->nullable();
            $table->jsonb('dns_verification')->nullable();
        });

        Schema::create('ingress_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('purpose', 32)->unique();
            $table->text('ciphertext');
            $table->text('wrapped_dek');
            $table->string('kek_id', 64);
            $table->timestamps();
        });

        Schema::table('domains', function (Blueprint $table): void {
            $table->string('cert_issuer', 256)->nullable();
            $table->timestamp('cert_not_before')->nullable();
            $table->boolean('cert_wildcard')->default(false);
            $table->string('cert_error', 1024)->nullable();
            $table->timestamp('cert_error_at')->nullable();
            $table->timestamp('cert_checked_at')->nullable();
            $table->foreignId('cert_server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->boolean('hsts')->default(true);
        });

        Schema::table('servers', function (Blueprint $table): void {
            $table->jsonb('ingress_status')->nullable();
            $table->timestamp('ingress_status_at')->nullable();
        });

        Schema::table('placements', function (Blueprint $table): void {
            $table->unsignedInteger('mesh_port')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('placements', function (Blueprint $table): void {
            $table->dropColumn('mesh_port');
        });
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn(['ingress_status', 'ingress_status_at']);
        });
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cert_server_id');
            $table->dropColumn(['cert_issuer', 'cert_not_before', 'cert_wildcard', 'cert_error', 'cert_error_at', 'cert_checked_at', 'hsts']);
        });
        Schema::dropIfExists('ingress_credentials');
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->dropColumn(['dns_provider', 'dns_verified_at', 'dns_verification']);
        });
    }
};
