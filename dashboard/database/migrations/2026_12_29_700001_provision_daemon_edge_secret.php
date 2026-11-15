<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * The edge secret (App\Services\Daemon\DaemonEdgeSecret) is created when
 * client certificates are switched on instead of on first render. An install
 * that has them on but never rendered the control plane since gets it here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $secret = 'lr_edge_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        DB::table('instance_settings')
            ->whereIn('daemon_client_certificates', ['verify', 'require'])
            ->where(static fn (Builder $query): Builder => $query->whereNull('daemon_edge_secret')->orWhere('daemon_edge_secret', ''))
            ->update(['daemon_edge_secret' => Crypt::encryptString($secret)]);
    }

    public function down(): void {}
};
