<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Models\Server;
use Carbon\CarbonInterface;
use DateTimeZone;
use Throwable;

/**
 * When automatic updates may start (docs/11 §4): the hours of config
 * `launchroom.updates.auto_window` (start inclusive, end exclusive) in the
 * control-plane server's time zone from its hardware facts, else UTC.
 */
final readonly class AutoUpdateWindow
{
    public function contains(CarbonInterface $now): bool
    {
        [$start, $end] = $this->hours();
        $hour = (int) $now->copy()->setTimezone($this->timezone())->format('G');

        return $start <= $end ? $hour >= $start && $hour < $end : $hour >= $start || $hour < $end;
    }

    /**
     * @return array{int, int}
     */
    public function hours(): array
    {
        $window = config()->array('launchroom.updates.auto_window');

        return [
            is_int($window['start'] ?? null) ? $window['start'] : 2,
            is_int($window['end'] ?? null) ? $window['end'] : 5,
        ];
    }

    public function timezone(): DateTimeZone
    {
        $zone = Server::query()->where('is_control_plane', true)->whereNull('revoked_at')->first()?->facts->hardware?->timezone;

        try {
            return new DateTimeZone(is_string($zone) && $zone !== '' ? $zone : 'UTC');
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
