<?php

declare(strict_types=1);

namespace App\Enums\Moves;

/**
 * How one service moves (docs/14 §13.2):
 *
 * - stateless: redeployed with the source draining (rolling, no downtime);
 * - dump: Postgres, MySQL/MariaDB and MongoDB move as a logical dump
 *   (D55 db_export → db_import), which works across CPU architectures and
 *   engine builds; the database's apps stop while it is copied;
 * - files: app volumes, and the data volume of engines without an import
 *   (Redis, Valkey, ClickHouse), move as a tarball of the stopped volume
 *   (volume_export → volume_import); the service stops while it is copied.
 */
enum MoveItemKind: string
{
    case Stateless = 'stateless';
    case Dump = 'dump';
    case Files = 'files';

    public function isStateful(): bool
    {
        return $this !== self::Stateless;
    }
}
