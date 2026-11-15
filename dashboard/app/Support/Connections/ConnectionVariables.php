<?php

declare(strict_types=1);

namespace App\Support\Connections;

use App\Data\Services\DatabaseSource;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Exceptions\DomainRuleViolation;
use App\Models\Connection;
use App\Models\Service;
use App\Models\ServiceInstance;

/**
 * What a connection from → to injects (docs/06 §3.2): framework-aware names
 * for databases, what the provider declares itself (ProvidedVariables, D65),
 * or `<SLUG>_URL` for app → app. Values are reference templates
 * (environment-relative), never resolved secrets. Shared by the canvas
 * preview and CreateConnection, so both apply the same rules.
 */
final readonly class ConnectionVariables
{
    public function __construct(
        private DatabaseEngines $engines,
    ) {}

    /**
     * @return array<string, string> key => reference template
     *
     * @throws DomainRuleViolation when the connection isn't possible
     */
    public function for(Service $from, Service $to, ConnectionMode $mode, ConnectionStyle $style): array
    {
        if ($from->id === $to->id) {
            throw DomainRuleViolation::on('to', 'A service can’t connect to itself.');
        }

        if ($from->project_id !== $to->project_id) {
            throw DomainRuleViolation::on('to', 'Connect two services of the same project.');
        }

        $source = $to->source;

        if (! $source instanceof DatabaseSource && ! $to->kind->isWeb()) {
            throw DomainRuleViolation::on('to', 'Workers and crons have no address to connect to.');
        }

        if ($source instanceof DatabaseSource && $mode === ConnectionMode::Public) {
            throw DomainRuleViolation::on('mode', 'Databases are private (docs: public database access is an admin-only toggle).');
        }

        if (Connection::query()->where('from_service_id', $from->id)->where('to_service_id', $to->id)->exists()) {
            throw DomainRuleViolation::conflict("{$from->name} is already connected to {$to->name}.");
        }

        if ($source instanceof DatabaseSource) {
            return $this->engines->for($source->engine)->connectionVariables($style, $to->slug);
        }

        // Provided variables say themselves which address they use.
        $provided = ProvidedVariables::of($to);

        if ($provided !== null) {
            return $provided;
        }

        $key = strtoupper(str_replace('-', '_', $to->slug)).'_URL';
        $exported = $mode === ConnectionMode::Public ? 'PUBLIC_URL' : 'PRIVATE_URL';

        return [$key => "\${{{$to->slug}.{$exported}}}"];
    }

    /**
     * Databases are private, and provided variables say themselves which
     * address they use.
     *
     * @return list<ConnectionMode>
     */
    public static function modes(Service $to): array
    {
        return $to->source instanceof DatabaseSource || ProvidedVariables::of($to) !== null
            ? [ConnectionMode::Private]
            : [ConnectionMode::Private, ConnectionMode::Public];
    }

    /**
     * @return list<ConnectionStyle>
     */
    public static function styles(Service $to): array
    {
        return $to->source instanceof DatabaseSource ? ConnectionStyle::cases() : [ConnectionStyle::Url];
    }

    /**
     * Laravel apps (and their workers and crons) get Laravel names, the rest
     * one URL variable. The framework comes from the app's last build plan;
     * before its first plan the icon picked when it was added is the only
     * hint there is.
     */
    public static function defaultStyle(Service $from, Service $to): ConnectionStyle
    {
        $styles = self::styles($to);
        $app = $from->parent ?? $from;
        $framework = $app->instances()->get(['id', 'plan'])
            ->map(static fn (ServiceInstance $instance): ?string => $instance->plan?->summary?->framework)
            ->first(static fn (?string $framework): bool => $framework !== null);
        $laravel = $framework !== null ? $framework === 'laravel' : $app->icon === 'laravel' || $from->icon === 'laravel';

        return $laravel && in_array(ConnectionStyle::Laravel, $styles, true) ? ConnectionStyle::Laravel : ConnectionStyle::Url;
    }
}
