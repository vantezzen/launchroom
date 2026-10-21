<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mesh-published ports (docs/05 §3): a port is unique per server
 * (`App\Services\NodeStates\MeshLinks` allocates under an advisory lock; this
 * partial unique index is the backstop).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX placements_server_mesh_port_unique ON placements (server_id, mesh_port) WHERE mesh_port IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS placements_server_mesh_port_unique');
    }
};
