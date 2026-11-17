<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Data\Servers\RequestRejectionData;
use App\Enums\Servers\RequestRejection;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers why the dashboard refused a request that `$server` signed
 * correctly (`servers.last_rejection`), so its page names the reason
 * (clock skew, revoked key, client certificate) instead of a generic
 * checklist. Callers verified the signature first: a request anyone could
 * have sent never writes here. At most one write per server and minute.
 */
final readonly class RecordRequestRejection
{
    public const int EVERY_SECONDS = 60;

    public function handle(Server $server, RequestRejection $reason, ?int $skewSeconds = null): bool
    {
        if (! Cache::add("lr:daemon:rejection:{$server->id}", 1, self::EVERY_SECONDS)) {
            return false;
        }

        Server::query()->whereKey($server->id)->update([
            'last_rejection' => json_encode(new RequestRejectionData($reason, now()->toIso8601ZuluString(), $skewSeconds)->toArray(), JSON_THROW_ON_ERROR),
        ]);

        return true;
    }
}
