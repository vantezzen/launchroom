<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project icons (docs/08 §13, docs/09 R32): a favicon fetched from the
 * project's primary production domain, an uploaded image or none. The file
 * lives on the dashboard's private disk under a generated path; the row
 * keeps where it came from and a content hash for the URL.
 *
 * Commit author avatars (docs/08 §13): the GitHub avatar URL of the commit
 * author, always on avatars.githubusercontent.com (App\Support\Git\GitHubAvatar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('icon_mode', 16)->default('automatic');
            $table->string('icon_path')->nullable();
            $table->string('icon_type', 8)->nullable();
            $table->string('icon_version', 16)->nullable();
            $table->string('icon_source', 255)->nullable();
            $table->timestamp('icon_checked_at')->nullable();
        });

        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_icon_mode_check CHECK (icon_mode IN ('automatic', 'uploaded', 'none'))");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_icon_type_check CHECK (icon_type IS NULL OR icon_type IN ('png', 'webp', 'svg'))");

        Schema::table('deployments', function (Blueprint $table): void {
            $table->string('commit_author_avatar_url', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['icon_mode', 'icon_path', 'icon_type', 'icon_version', 'icon_source', 'icon_checked_at']);
        });

        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropColumn('commit_author_avatar_url');
        });
    }
};
