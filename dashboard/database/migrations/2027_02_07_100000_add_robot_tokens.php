<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Robot tokens (D111, docs/16 §1.4): a robot is a `users` row owned by a
 * team (`robot_team_id`) with no membership; it outlives the member who
 * created it. Any token may carry a scope: one project, or one service of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('robot_team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->foreignId('robot_created_by_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_id');
            $table->dropConstrainedForeignId('project_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('robot_created_by_id');
            $table->dropConstrainedForeignId('robot_team_id');
        });
    }
};
