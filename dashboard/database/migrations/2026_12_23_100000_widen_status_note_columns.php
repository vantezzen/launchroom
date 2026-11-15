<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every status note (an error or reason saved with a transition) is capped at
 * HasStatusTransitions::NOTE_LIMIT; these columns were narrower.
 */
return new class extends Migration
{
    private const array COLUMNS = [
        'alerts' => 'resolve_reason',
        'alert_deliveries' => 'error',
        'previews' => 'status_reason',
        'git_connections' => 'status_reason',
        'webhook_deliveries' => 'error',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            Schema::table($table, function (Blueprint $table) use ($column): void {
                $table->string($column, 2048)->nullable()->change();
            });
        }
    }
};
