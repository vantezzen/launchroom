<?php

declare(strict_types=1);

namespace App\Services\Transfers;

/**
 * What {@see FileSniffer} read from the start of an uploaded file.
 */
final readonly class SniffResult
{
    /**
     * @param  string|null  $format  protocol DataFormat (null: not recognised)
     * @param  'postgres'|'mysql'|'mariadb'|'mongodb'|null  $family  who made a dump
     * @param  string|null  $serverVersion  the source server's version ("15.8")
     * @param  string|null  $toolVersion  the dump tool's version (pg_dump)
     * @param  list<string>  $entries  first archive entries (volumes)
     * @param  bool  $entriesComplete  every entry was listed
     */
    public function __construct(
        public ?string $format,
        public bool $compressed = false,
        public ?string $family = null,
        public ?string $serverVersion = null,
        public ?string $toolVersion = null,
        public ?string $refusal = null,
        public array $entries = [],
        public bool $entriesComplete = false,
    ) {}

    public static function unrecognised(?string $refusal = null): self
    {
        return new self(null, refusal: $refusal);
    }

    /**
     * "PostgreSQL 15.8", "MySQL 8.0.36", "MariaDB 10.11.6", "MongoDB 7.0.5".
     */
    public function sourceVersionLabel(): ?string
    {
        if ($this->serverVersion === null || $this->family === null) {
            return null;
        }

        return match ($this->family) {
            'postgres' => 'PostgreSQL',
            'mysql' => 'MySQL',
            'mariadb' => 'MariaDB',
            'mongodb' => 'MongoDB',
        }.' '.$this->serverVersion;
    }

    /**
     * One line for the UI: "PostgreSQL custom dump · from PostgreSQL 15.8".
     */
    public function detail(): ?string
    {
        $kind = match ($this->format) {
            'postgres_custom' => 'PostgreSQL custom dump',
            'postgres_sql' => 'PostgreSQL SQL dump',
            'mysql_sql' => ($this->family === 'mariadb' ? 'MariaDB' : 'MySQL').' SQL dump',
            'mongodb_archive' => 'MongoDB archive',
            'tar' => $this->compressed ? 'tar.gz archive' : 'tar archive',
            'zip' => 'zip archive',
            default => null,
        };

        if ($kind === null) {
            return null;
        }

        if ($this->compressed && $this->format !== 'tar') {
            $kind .= ', gzipped';
        }

        $from = $this->sourceVersionLabel();

        return $from === null ? $kind : "{$kind} · from {$from}";
    }

    /**
     * The archive's single top folder ("uploads"), when every listed entry is inside one.
     */
    public function topFolder(): ?string
    {
        $top = null;

        foreach ($this->entries as $entry) {
            $entry = ltrim(preg_replace('#^\./#', '', $entry) ?? $entry, '/');

            if ($entry === '' || $entry === '.') {
                continue;
            }

            $first = explode('/', $entry, 2)[0];

            if (! str_contains($entry, '/') && ! str_ends_with($entry, '/')) {
                return null; // A file at the top.
            }

            if ($top !== null && $top !== $first) {
                return null;
            }

            $top = $first;
        }

        return $top;
    }
}
