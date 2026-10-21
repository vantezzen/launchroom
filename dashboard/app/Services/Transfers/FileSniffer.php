<?php

declare(strict_types=1);

namespace App\Services\Transfers;

/**
 * Recognises an uploaded file from its first bytes (docs/14 §12.3), so the
 * dashboard can say what it is and refuse a wrong or too new dump before
 * anything starts. launchroomd reads the file again itself and is the one
 * that decides; this is early feedback with the same rules.
 *
 * Databases: gzip is looked through; `PGDMP` (pg_dump -Fc, versions from its
 * header), pg_dump plain SQL (`-- Dumped from database version`), mysqldump /
 * mariadb-dump SQL (`-- Server version`), mongodump archives (magic
 * 0x8199e26d, `server_version` in the header). Volumes: tar (optionally
 * gzipped) and zip, with the first entry names.
 */
final class FileSniffer
{
    /** How much of a compressed file is inflated to look at it. */
    private const int INFLATE_LIMIT = 4 * 1024 * 1024;

    public function database(string $head): SniffResult
    {
        $compressed = $this->isGzip($head);
        $bytes = $compressed ? $this->inflate($head) : $head;

        if ($bytes === '') {
            return SniffResult::unrecognised();
        }

        if (str_starts_with($bytes, 'PGDMP')) {
            return $this->postgresCustom($bytes, $compressed);
        }

        if (str_starts_with($bytes, "\x6d\xe2\x99\x81")) {
            return new SniffResult('mongodb_archive', $compressed, 'mongodb', $this->mongoServerVersion($bytes));
        }

        $text = substr($bytes, 0, 256 * 1024);

        if (str_contains($text, '-- PostgreSQL database cluster dump')) {
            return SniffResult::unrecognised('This is a pg_dumpall file with every database and role. Dump one database with pg_dump instead.');
        }

        if (str_contains($text, '-- PostgreSQL database dump') || str_contains($text, '-- Dumped from database version')) {
            return new SniffResult(
                'postgres_sql',
                $compressed,
                'postgres',
                self::match('/^-- Dumped from database version ([0-9][0-9A-Za-z.]*)/m', $text),
                self::match('/^-- Dumped by pg_dump version ([0-9][0-9A-Za-z.]*)/m', $text),
            );
        }

        $mariaHeader = str_contains($text, '-- MariaDB dump') || str_starts_with($text, '/*M!999999');

        if ($mariaHeader || str_contains($text, '-- MySQL dump')) {
            $server = self::match('/^-- Server version\s+([0-9][0-9A-Za-z.+_~-]*)/m', $text);
            $family = $mariaHeader || ($server !== null && stripos($server, 'mariadb') !== false) ? 'mariadb' : 'mysql';
            $version = $server === null ? null : self::match('/^([0-9]+(?:\.[0-9]+){0,2})/', $server);

            return new SniffResult('mysql_sql', $compressed, $family, $version);
        }

        return SniffResult::unrecognised();
    }

    /**
     * @param  string|null  $tail  the last chunk (zip central directory)
     */
    public function archive(string $head, ?string $tail = null): SniffResult
    {
        if (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06")) {
            [$entries, $complete] = $tail === null ? [[], false] : $this->zipEntries($tail);

            return new SniffResult('zip', entries: $entries, entriesComplete: $complete);
        }

        $compressed = $this->isGzip($head);
        $bytes = $compressed ? $this->inflate($head) : $head;

        if (strlen($bytes) >= 512 && substr($bytes, 257, 5) === 'ustar') {
            [$entries, $complete] = $this->tarEntries($bytes);

            return new SniffResult('tar', $compressed, entries: $entries, entriesComplete: $complete);
        }

        return SniffResult::unrecognised();
    }

    /**
     * Whether $engine (the target's) can import $result, and why not. Version
     * rules (docs/14 §12.3): a dump from the same or an older major version
     * imports; a newer one is refused naming both.
     */
    public static function refusal(SniffResult $result, string $engine, string $targetVersion, string $serviceName): ?string
    {
        if ($result->refusal !== null) {
            return $result->refusal;
        }

        $label = match ($engine) {
            'postgres' => 'PostgreSQL',
            'mysql' => 'MySQL',
            'mongodb' => 'MongoDB',
            default => $engine,
        };
        $accepts = match ($engine) {
            'postgres' => ['postgres_custom', 'postgres_sql'],
            'mysql' => ['mysql_sql'],
            'mongodb' => ['mongodb_archive'],
            default => [],
        };

        if ($result->format === null) {
            return 'launchroom couldn’t recognise this file. Supported: pg_dump -Fc or plain SQL (.sql, .sql.gz), mysqldump SQL, mongodump --archive.';
        }

        if (! in_array($result->format, $accepts, true)) {
            return "This is a {$result->detail()}, but {$serviceName} is {$label}.";
        }

        $newer = static fn (?string $version): bool => $version !== null && version_compare(self::major($version, $engine), self::major($targetVersion, $engine), '>');

        if ($engine === 'mysql' && $result->family === 'mariadb') {
            return null; // Different families: no comparable versions (a warning when it runs).
        }

        if ($newer($result->serverVersion)) {
            return "This dump comes from {$label} {$result->serverVersion}, but {$serviceName} runs {$label} {$targetVersion}. Pick ".self::major((string) $result->serverVersion, $engine).' or newer for the database, or dump the data with an older tool.';
        }

        if ($engine === 'postgres' && $newer($result->toolVersion)) {
            return "This dump was made with pg_dump {$result->toolVersion}, but {$serviceName} runs PostgreSQL {$targetVersion}. Dump it with pg_dump {$targetVersion} or older, or pick a newer version for the database.";
        }

        return null;
    }

    /**
     * The comparable part of a version: Postgres majors ("17", "9.6"),
     * MySQL and MongoDB major.minor ("8.4", "7.0").
     */
    public static function major(string $version, string $engine): string
    {
        preg_match('/^([0-9]+)(?:\.([0-9]+))?/', $version, $m);
        $first = (int) ($m[1] ?? 0);
        $second = (int) ($m[2] ?? 0);

        if ($engine === 'postgres') {
            return $first >= 10 ? (string) $first : "{$first}.{$second}";
        }

        return "{$first}.{$second}";
    }

    private function u16(string $bytes, int $offset): int
    {
        return new BinaryReader($bytes, $offset)->int(2, false) ?? 0;
    }

    private function u32(string $bytes, int $offset): int
    {
        return new BinaryReader($bytes, $offset)->int(4, false) ?? 0;
    }

    private function isGzip(string $bytes): bool
    {
        return str_starts_with($bytes, "\x1f\x8b");
    }

    /**
     * Inflates the start of a gzip stream (a truncated stream is fine).
     */
    private function inflate(string $bytes): string
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);

        if ($context === false) {
            return '';
        }

        $out = '';

        foreach (str_split($bytes, 65536) as $piece) {
            $part = @inflate_add($context, $piece, ZLIB_SYNC_FLUSH);

            if ($part === false) {
                break;
            }

            $out .= $part;

            if (strlen($out) >= self::INFLATE_LIMIT) {
                break;
            }
        }

        return $out;
    }

    private static function match(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $m) === 1 ? $m[1] : null;
    }

    /**
     * pg_dump's custom-format header (pg_backup_archiver.c ReadHead): the
     * source server's and pg_dump's version strings (archive 1.10+).
     */
    private function postgresCustom(string $bytes, bool $compressed): SniffResult
    {
        $reader = new BinaryReader($bytes, 5);
        $major = $reader->byte();
        $minor = $reader->byte();
        $revision = ($major > 1 || ($major === 1 && $minor > 0)) ? $reader->byte() : 0;
        $version = ($major * 256 + $minor) * 256 + $revision;
        $v = static fn (int $a, int $b): int => ($a * 256 + $b) * 256;
        $intSize = $reader->byte();

        if ($intSize === null || $intSize < 1 || $intSize > 8) {
            return new SniffResult('postgres_custom', $compressed, 'postgres');
        }

        if ($version >= $v(1, 7)) {
            $reader->byte(); // offSize
        }

        $reader->byte(); // format

        if ($version >= $v(1, 15)) {
            $reader->byte();
        } elseif ($version >= $v(1, 4)) {
            $reader->int($intSize, true);
        } elseif ($version >= $v(1, 2)) {
            $reader->byte();
        }

        $remote = null;
        $dump = null;

        if ($version >= $v(1, 4)) {
            for ($i = 0; $i < 7; $i++) {
                $reader->int($intSize, true);
            }

            $reader->str($intSize); // database name
        }

        if ($version >= $v(1, 10)) {
            $remote = $reader->str($intSize);
            $dump = $reader->str($intSize);
        }

        $clean = static fn (?string $s): ?string => $s === null ? null : self::match('/^([0-9]+(?:\.[0-9]+)*)/', $s);

        return new SniffResult('postgres_custom', $compressed, 'postgres', $clean($remote), $clean($dump));
    }

    /**
     * `server_version` from the BSON header after the archive magic.
     */
    private function mongoServerVersion(string $bytes): ?string
    {
        $reader = new BinaryReader($bytes, 4);
        $length = $reader->int(4, false);

        if ($length === null || $length < 5 || $length > 16384) {
            return null;
        }

        $end = 4 + $length - 1;

        while ($reader->offset() < $end) {
            $type = $reader->byte();
            $name = $reader->cstring();

            if ($type === null || $name === null) {
                return null;
            }

            switch ($type) {
                case 0x02:
                    $value = $reader->bsonString();

                    if ($name === 'server_version') {
                        return $value === null ? null : self::match('/^([0-9]+(?:\.[0-9]+)*)/', $value);
                    }

                    break;
                case 0x10:
                    $reader->skip(4);
                    break;
                case 0x12:
                case 0x01:
                case 0x09:
                    $reader->skip(8);
                    break;
                case 0x08:
                    $reader->skip(1);
                    break;
                default:
                    return null;
            }
        }

        return null;
    }

    /**
     * @return array{list<string>, bool}
     */
    private function tarEntries(string $bytes): array
    {
        $entries = [];
        $offset = 0;
        $length = strlen($bytes);

        while ($offset + 512 <= $length && count($entries) < 200) {
            $header = substr($bytes, $offset, 512);

            if (trim($header, "\0") === '') {
                return [$entries, true];
            }

            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $type = $header[156];
            $size = (int) octdec(trim(substr($header, 124, 12), "\0 ") ?: '0');

            if (! in_array($type, ['x', 'g', 'L', 'K'], true)) {
                $entries[] = $prefix === '' ? $name : $prefix.'/'.$name;
            }

            $offset += 512 + (int) ceil($size / 512) * 512;
        }

        return [$entries, false];
    }

    /**
     * Names from the zip central directory (end of the file).
     *
     * @return array{list<string>, bool}
     */
    private function zipEntries(string $tail): array
    {
        $eocd = strrpos($tail, "PK\x05\x06");

        if ($eocd === false || strlen($tail) < $eocd + 22) {
            return [[], false];
        }

        $count = $this->u16($tail, $eocd + 10);
        $size = $this->u32($tail, $eocd + 12);
        $start = $eocd - $size;

        if ($start < 0) {
            return [[], false];
        }

        $entries = [];
        $offset = $start;

        for ($i = 0; $i < $count && count($entries) < 200; $i++) {
            if (substr($tail, $offset, 4) !== "PK\x01\x02") {
                return [$entries, false];
            }

            $nameLength = $this->u16($tail, $offset + 28);
            $extraLength = $this->u16($tail, $offset + 30);
            $commentLength = $this->u16($tail, $offset + 32);
            $entries[] = substr($tail, $offset + 46, $nameLength);
            $offset += 46 + $nameLength + $extraLength + $commentLength;
        }

        return [$entries, count($entries) === $count];
    }
}
