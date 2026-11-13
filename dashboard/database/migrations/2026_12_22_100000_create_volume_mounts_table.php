<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared volumes (D73, docs/07 `volume_mounts`): a volume of one service
 * mounted by another service of the same environment, at its own path.
 * The volume row keeps its owner (backups, SFTP, moves); a mount is intent
 * like every other setting and applies with the next deploy. Detaching
 * deletes the row: the running deployment keeps mounting what its revision
 * recorded until the next deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volume_mounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('volume_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_instance_id')->constrained()->cascadeOnDelete();
            $table->string('mount_path', 255);
            $table->timestamps();

            $table->unique(['volume_id', 'service_instance_id']);
            $table->unique(['service_instance_id', 'mount_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volume_mounts');
    }
};
