<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D27: the per-server agent is now launchroomd ("the daemon").
 *
 * - agent_tasks → daemon_tasks, including its id sequence and every
 *   constraint and index named after it;
 * - agent_task_id → daemon_task_id on log_chunks, backup_runs and
 *   restore_runs (foreign keys and indexes renamed along);
 * - servers.agent_version → servers.daemon_version, and the agentVersion
 *   key of servers.host_status (the next report rewrites it anyway).
 *
 * Postgres only (like the rest of the schema). Rows keep their ids; no data
 * moves.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array TASK_ID_TABLES = ['log_chunks', 'backup_runs', 'restore_runs'];

    public function up(): void
    {
        $this->rename('agent', 'daemon');
    }

    public function down(): void
    {
        $this->rename('daemon', 'agent');
    }

    private function rename(string $from, string $to): void
    {
        Schema::rename("{$from}_tasks", "{$to}_tasks");
        DB::statement("ALTER SEQUENCE {$from}_tasks_id_seq RENAME TO {$to}_tasks_id_seq");

        foreach (self::TASK_ID_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($from, $to): void {
                $blueprint->renameColumn("{$from}_task_id", "{$to}_task_id");
            });
        }

        // Constraints first (renaming a primary key/unique constraint renames
        // its index too), then the remaining plain indexes.
        $patterns = ["{$from}\\_tasks\\_%", "%\\_{$from}\\_task\\_id\\_%"];
        $constraints = DB::table('pg_constraint')
            ->whereRaw('connamespace = current_schema()::regnamespace')
            ->whereRaw('(conname LIKE ? OR conname LIKE ?)', $patterns)
            ->selectRaw("conrelid::regclass::text || ' ' || conname AS item")
            ->pluck('item');
        foreach ($constraints as $item) {
            if (! is_string($item)) {
                continue;
            }
            [$table, $name] = explode(' ', $item, 2);
            DB::statement(sprintf('ALTER TABLE %s RENAME CONSTRAINT %s TO %s', $table, $name, $this->renamed($name, $from, $to)));
        }

        $indexes = DB::table('pg_indexes')
            ->whereRaw('schemaname = current_schema()')
            ->whereRaw('(indexname LIKE ? OR indexname LIKE ?)', $patterns)
            ->pluck('indexname');
        foreach ($indexes as $index) {
            if (! is_string($index)) {
                continue;
            }
            DB::statement(sprintf('ALTER INDEX %s RENAME TO %s', $index, $this->renamed($index, $from, $to)));
        }

        Schema::table('servers', function (Blueprint $table) use ($from, $to): void {
            $table->renameColumn("{$from}_version", "{$to}_version");
        });

        DB::statement(
            "UPDATE servers
             SET host_status = (host_status - '{$from}Version') || jsonb_build_object('{$to}Version', host_status -> '{$from}Version')
             WHERE host_status -> '{$from}Version' IS NOT NULL",
        );
    }

    private function renamed(string $name, string $from, string $to): string
    {
        return str_replace(["{$from}_tasks_", "_{$from}_task_id_"], ["{$to}_tasks_", "_{$to}_task_id_"], $name);
    }
};
