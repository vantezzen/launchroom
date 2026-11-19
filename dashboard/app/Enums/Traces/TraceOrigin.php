<?php

declare(strict_types=1);

namespace App\Enums\Traces;

/**
 * Who produced the response of a traced request (docs/23 §2.2): the byte
 * value of the trace's `origin` field.
 */
enum TraceOrigin: string
{
    case App = 'app';
    case Firewall = 'firewall';
    case UpstreamFailure = 'upstream_failure';
    case Stopped = 'stopped';
    case Redirect = 'redirect';
    case NoRoute = 'no_route';
    case AccessRefused = 'access_refused';
    case Edge = 'edge';

    public static function fromByte(int $value): ?self
    {
        return match ($value) {
            1 => self::App,
            2 => self::Firewall,
            3 => self::UpstreamFailure,
            4 => self::Stopped,
            5 => self::Redirect,
            6 => self::NoRoute,
            7 => self::AccessRefused,
            8 => self::Edge,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::App => 'The app',
            self::Firewall => 'The firewall',
            self::UpstreamFailure => 'The edge (app unreachable)',
            self::Stopped => 'The edge (service stopped)',
            self::Redirect => 'The edge (redirect)',
            self::NoRoute => 'The edge (no app at this address)',
            self::AccessRefused => 'The edge (access refused)',
            self::Edge => 'The edge',
        };
    }
}
