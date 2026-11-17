<?php

declare(strict_types=1);

namespace App\Data\Servers;

use App\Enums\Servers\RequestRejection;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * `servers.last_rejection`: the last request this server signed correctly
 * that the dashboard still refused, and why. `skewSeconds` is how far its
 * clock is ahead (positive) or behind (negative) the dashboard's.
 */
final class RequestRejectionData extends Data
{
    public function __construct(
        public RequestRejection $reason,
        public string $at,
        public ?int $skewSeconds = null,
    ) {}

    /**
     * Whether it still explains the server's state: refused after its last
     * accepted report.
     */
    public function current(?CarbonInterface $lastReportAt): bool
    {
        return ! $lastReportAt instanceof CarbonInterface || CarbonImmutable::parse($this->at)->greaterThan($lastReportAt);
    }

    /**
     * What happened and what to do, for the server page and the offline alert.
     *
     * @return array{title: string, detail: string}
     */
    public function explain(): array
    {
        $seconds = abs($this->skewSeconds ?? 0);
        $direction = ($this->skewSeconds ?? 0) > 0 ? 'ahead of' : 'behind';

        return match ($this->reason) {
            RequestRejection::ClockSkew => [
                'title' => "Its clock is {$seconds} seconds {$direction} the dashboard",
                'detail' => 'The dashboard refuses requests more than a minute off, so it can’t connect. Turn on time sync on that machine (`sudo timedatectl set-ntp true`). It reconnects by itself.',
            ],
            RequestRejection::Revoked => [
                'title' => 'Its key was revoked',
                'detail' => 'The dashboard refuses this server since it was removed or replaced. To use the machine again, add it as a new server.',
            ],
            RequestRejection::Certificate => [
                'title' => 'Its client certificate was refused',
                'detail' => 'The certificate it showed is unknown, expired, revoked or belongs to another server. It asks for a new one by itself. If it stays refused, allow a new certificate in Settings → Instance security → Server certificates.',
            ],
            RequestRejection::CertificateRequired => [
                'title' => 'It has no client certificate',
                'detail' => 'This dashboard requires client certificates and the server didn’t show one. Allow it a new certificate in Settings → Instance security → Server certificates.',
            ],
        };
    }
}
