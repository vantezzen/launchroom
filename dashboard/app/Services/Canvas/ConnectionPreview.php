<?php

declare(strict_types=1);

namespace App\Services\Canvas;

use App\Data\Canvas\ConnectionPreviewData;
use App\Data\Canvas\ConnectionVariableData;
use App\Data\Services\DatabaseSource;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Models\Connection;
use App\Models\Service;

/**
 * Previews the variables a connection would inject (docs/06 §3.2), with the
 * same naming as App\Actions\Connections\CreateConnection: framework-aware
 * names for databases, `<SLUG>_URL` for app → app. Values are reference
 * templates, never resolved secrets.
 */
final readonly class ConnectionPreview
{
    public function __construct(
        private DatabaseEngines $engines,
    ) {}

    public function preview(Service $from, Service $to, ?ConnectionMode $mode, ?ConnectionStyle $style): ConnectionPreviewData
    {
        $source = $to->source;
        $database = $source instanceof DatabaseSource;
        $modes = $database ? [ConnectionMode::Private] : [ConnectionMode::Private, ConnectionMode::Public];
        $styles = $database ? ConnectionStyle::cases() : [ConnectionStyle::Url];
        $mode = in_array($mode, $modes, true) ? $mode : ConnectionMode::Private;
        $style = in_array($style, $styles, true) ? $style : $this->defaultStyle($from, $styles);

        $error = match (true) {
            $from->id === $to->id => 'A service can’t connect to itself.',
            $from->project_id !== $to->project_id => 'Connect two services of the same project.',
            ! $database && ! $to->kind->isWeb() => 'Workers and crons have no address to connect to.',
            Connection::query()->where('from_service_id', $from->id)->where('to_service_id', $to->id)->exists() => "{$from->name} is already connected to {$to->name}.",
            default => null,
        };

        $variables = [];

        if ($error === null) {
            $injected = $source instanceof DatabaseSource
                ? $this->engines->for($source->engine)->connectionVariables($style, $to->slug)
                : [strtoupper(str_replace('-', '_', $to->slug)).'_URL' => '${{'.$to->slug.'.'.($mode === ConnectionMode::Public ? 'PUBLIC_URL' : 'PRIVATE_URL').'}}'];

            foreach ($injected as $key => $value) {
                $variables[] = new ConnectionVariableData($key, $value);
            }
        }

        return new ConnectionPreviewData($mode, $style, $styles, $modes, $variables, $error);
    }

    /**
     * Laravel apps get Laravel names; everything else one URL variable.
     *
     * @param  list<ConnectionStyle>  $styles
     */
    private function defaultStyle(Service $from, array $styles): ConnectionStyle
    {
        $laravel = $from->icon === 'laravel' || ($from->parent !== null && $from->parent->icon === 'laravel');

        return $laravel && in_array(ConnectionStyle::Laravel, $styles, true) ? ConnectionStyle::Laravel : ConnectionStyle::Url;
    }
}
