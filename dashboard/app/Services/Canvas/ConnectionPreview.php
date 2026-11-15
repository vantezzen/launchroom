<?php

declare(strict_types=1);

namespace App\Services\Canvas;

use App\Data\Canvas\ConnectionPreviewData;
use App\Data\Canvas\ConnectionVariableData;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Exceptions\DomainRuleViolation;
use App\Models\Service;
use App\Support\Connections\ConnectionVariables;

/**
 * Previews what CreateConnection would inject (ConnectionVariables), with the
 * modes and styles the target allows. An unavailable mode or style falls
 * back to the default instead of failing.
 */
final readonly class ConnectionPreview
{
    public function __construct(
        private ConnectionVariables $connectionVariables,
    ) {}

    public function preview(Service $from, Service $to, ?ConnectionMode $mode, ?ConnectionStyle $style): ConnectionPreviewData
    {
        $modes = ConnectionVariables::modes($to);
        $styles = ConnectionVariables::styles($to);
        $mode = in_array($mode, $modes, true) ? $mode : ConnectionMode::Private;
        $style = in_array($style, $styles, true) ? $style : ConnectionVariables::defaultStyle($from, $to);
        $variables = [];
        $error = null;

        try {
            foreach ($this->connectionVariables->for($from, $to, $mode, $style) as $key => $value) {
                $variables[] = new ConnectionVariableData($key, $value);
            }
        } catch (DomainRuleViolation $refusal) {
            $error = $refusal->getMessage();
        }

        return new ConnectionPreviewData($mode, $style, $styles, $modes, $variables, $error);
    }
}
