<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use Spatie\LaravelData\Data;

/**
 * What the Connect popover shows before a connection is created (docs/06 §3.2).
 */
final class ConnectionPreviewData extends Data
{
    /**
     * @param  list<ConnectionStyle>  $styles  naming styles that apply to the target
     * @param  list<ConnectionMode>  $modes
     * @param  list<ConnectionVariableData>  $variables
     */
    public function __construct(
        public ConnectionMode $mode,
        public ConnectionStyle $style,
        public array $styles,
        public array $modes,
        public array $variables,
        /** Set when the connection can't be made (already connected, worker target…). */
        public ?string $error = null,
    ) {}
}
