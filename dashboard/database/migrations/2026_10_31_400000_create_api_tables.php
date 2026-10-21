<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public API v1 and CLI sign-in (docs/16): team-bound personal access
 * tokens with last-used IP, idempotency keys, and CLI device-flow requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tokens issued before the API existed had no team: none were ever
        // handed out (no UI, no endpoint), so dropping them is safe and keeps
        // `team_id` NOT NULL (docs/09 R11 "team-bound").
        DB::table('personal_access_tokens')->delete();

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->string('public_id', 30)->unique()->after('id');
            $table->foreignId('team_id')->after('tokenable_id')->constrained()->cascadeOnDelete();
            $table->string('client', 16)->default('dashboard')->after('abilities');
            $table->string('last_used_ip', 45)->nullable()->after('last_used_at');
            $table->string('created_ip', 45)->nullable()->after('last_used_ip');
            $table->index(['team_id', 'tokenable_id']);
        });

        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('personal_access_token_id')->constrained()->cascadeOnDelete();
            $table->string('key', 255);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('status')->nullable();
            $table->text('response')->nullable();
            $table->timestamp('created_at')->index();
            $table->unique(['personal_access_token_id', 'key']);
        });

        Schema::create('cli_login_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 30)->unique();
            $table->char('device_code_hash', 64)->unique();
            $table->string('user_code', 9)->unique();
            $table->string('status', 16)->default('pending');
            $table->string('client_name', 64);
            $table->string('client_hostname', 255)->nullable();
            $table->string('client_version', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()->constrained()->nullOnDelete();
            $table->text('token_ciphertext')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cli_login_requests');
        Schema::dropIfExists('api_idempotency_keys');

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['team_id', 'tokenable_id']);
            $table->dropConstrainedForeignId('team_id');
            $table->dropColumn(['public_id', 'client', 'last_used_ip', 'created_ip']);
        });
    }
};
