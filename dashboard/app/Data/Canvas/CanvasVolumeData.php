<?php

declare(strict_types=1);

namespace App\Data\Canvas;

use Spatie\LaravelData\Data;

/**
 * An app volume on the canvas (D73): its own node, with an edge to every
 * service that mounts it. Database volumes stay part of their database.
 */
final class CanvasVolumeData extends Data
{
    /**
     * @param  list<CanvasVolumeMountData>  $mounts  the owner first, then the services that share it
     */
    public function __construct(
        /** Volume public id (`vol_…`). */
        public string $id,
        /** The service it belongs to (backups, SFTP and removal go through it). */
        public string $ownerId,
        /** Where its owner mounts it. */
        public string $mountPath,
        public ?int $sizeBytes,
        /** The server holding it, once created. */
        public ?string $server,
        /** Created with the next deploy. */
        public bool $staged,
        /** Removed with the next deploy. */
        public bool $removing,
        public array $mounts,
        public ?CanvasPositionData $position,
    ) {}
}
