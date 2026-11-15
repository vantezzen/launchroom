<?php

declare(strict_types=1);

namespace App\Support\Ingress;

use App\Models\Deployment;
use App\Models\Domain;
use App\Support\PublicId;
use Illuminate\Support\Str;

/**
 * NodeState route ids (docs/05 §5, docs/15 §1.3): `rt_` + the ULID of the
 * domain the route serves, or of the deployment whose immutable URL it is.
 * Edges report requests, metrics and firewall samples by route id, so the
 * dashboard maps them back to the domain or deployment here.
 */
final class RouteId
{
    public const string PREFIX = 'rt';

    public static function forDomain(string $domainPublicId): string
    {
        return self::swap($domainPublicId, self::PREFIX);
    }

    public static function forDeployment(string $deploymentPublicId): string
    {
        return self::swap($deploymentPublicId, self::PREFIX);
    }

    /**
     * The public id of the domain a route id would belong to, or null for a
     * malformed route id.
     */
    public static function domainPublicId(string $routeId): ?string
    {
        return self::owner($routeId, Domain::publicIdPrefix());
    }

    public static function deploymentPublicId(string $routeId): ?string
    {
        return self::owner($routeId, Deployment::publicIdPrefix());
    }

    private static function owner(string $routeId, string $prefix): ?string
    {
        return PublicId::isValid($routeId, self::PREFIX) ? self::swap($routeId, $prefix) : null;
    }

    private static function swap(string $publicId, string $prefix): string
    {
        return $prefix.PublicId::SEPARATOR.Str::after($publicId, PublicId::SEPARATOR);
    }
}
