<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->string('name');
            $table->boolean('personal')->default(false);
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement("ALTER TABLE team_user ADD CONSTRAINT team_user_role_check CHECK (role IN ('owner', 'admin', 'developer', 'viewer'))");

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('current_team_id')->nullable()->after('remember_token')->constrained('teams')->nullOnDelete();
            // docs/07: instance owners may open operator tooling and must use 2FA (docs/09 R10).
            $table->boolean('is_instance_owner')->default(false)->after('current_team_id');
        });

        Schema::create('team_invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 16);
            // SHA-256 (hex) of the token; the token itself is only ever in the e-mail.
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invitations');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_team_id');
            $table->dropColumn('is_instance_owner');
        });
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
