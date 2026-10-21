<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client certificates for daemon transport (mTLS, D50, docs/03 §4.3): the
 * launchroom-internal CA, every certificate it issued, each server's
 * observed readiness and the instance mode (off/verify/require).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daemon_client_cas', function (Blueprint $table): void {
            $table->id();
            // PEM of the self-signed CA certificate (public).
            $table->text('certificate');
            // Lowercase hex SHA-256 of the DER.
            $table->char('fingerprint', 64)->unique();
            // PKCS#8 PEM of the CA key, envelope-encrypted (AD daemon_client_ca|<id>|key).
            $table->text('private_key');
            $table->text('wrapped_dek');
            $table->string('kek_id', 64);
            $table->timestampTz('not_before');
            $table->timestampTz('not_after');
            // Retired CAs are no longer trusted by the edge nor used to issue.
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('server_client_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ca_id')->constrained('daemon_client_cas')->restrictOnDelete();
            // Lowercase hex SHA-256 of the leaf's DER (Caddy's {http.request.tls.client.fingerprint}).
            $table->char('fingerprint', 64)->unique();
            $table->string('serial', 40);
            $table->timestampTz('not_before');
            $table->timestampTz('not_after');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason', 32)->nullable();
            $table->timestampTz('last_presented_at')->nullable();
            $table->timestampTz('created_at');

            $table->index(['server_id', 'not_after']);
        });

        Schema::table('servers', function (Blueprint $table): void {
            // Last signed request through the edge with a verified certificate of this server.
            $table->timestampTz('client_certificate_seen_at')->nullable();
            // Last signed request without one (through the edge or direct), while certificates are checked.
            $table->timestampTz('client_certificate_missing_at')->nullable();
            // An instance owner allowed a new certificate while certificates are required (1 hour).
            $table->timestampTz('client_certificate_reissue_until')->nullable();
        });

        Schema::table('instance_settings', function (Blueprint $table): void {
            // off (null) | verify | require (docs/03 §4.3).
            $table->string('daemon_client_certificates', 16)->nullable();
            $table->timestampTz('daemon_client_certificates_changed_at')->nullable();
            // The edge secret Caddy adds to proxied requests (X-Lr-Edge), encrypted with APP_KEY.
            $table->text('daemon_edge_secret')->nullable();
        });

        DB::statement("ALTER TABLE instance_settings ADD CONSTRAINT instance_settings_daemon_client_certificates_check CHECK (daemon_client_certificates IS NULL OR daemon_client_certificates IN ('off', 'verify', 'require'))");
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table): void {
            $table->dropColumn(['daemon_client_certificates', 'daemon_client_certificates_changed_at', 'daemon_edge_secret']);
        });

        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn(['client_certificate_seen_at', 'client_certificate_missing_at', 'client_certificate_reissue_until']);
        });

        Schema::dropIfExists('server_client_certificates');
        Schema::dropIfExists('daemon_client_cas');
    }
};
