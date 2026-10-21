<?php

declare(strict_types=1);

namespace App\Services\Transfers;

/**
 * A forward-only reader over a byte string for the file headers
 * {@see FileSniffer} parses. Reads past the end return null.
 */
final class BinaryReader
{
    public function __construct(
        private readonly string $bytes,
        private int $offset = 0,
    ) {}

    public function offset(): int
    {
        return $this->offset;
    }

    public function byte(): ?int
    {
        if ($this->offset >= strlen($this->bytes)) {
            return null;
        }

        return ord($this->bytes[$this->offset++]);
    }

    public function skip(int $n): void
    {
        $this->offset += $n;
    }

    /**
     * Little-endian integer of $size bytes; with $signed, pg_dump's form
     * (a sign byte first).
     */
    public function int(int $size, bool $signed): ?int
    {
        $negative = $signed ? $this->byte() : 0;

        if ($negative === null || $this->offset + $size > strlen($this->bytes)) {
            return null;
        }

        $value = 0;

        for ($i = 0; $i < $size; $i++) {
            $value += ord($this->bytes[$this->offset + $i]) << (8 * $i);
        }

        $this->offset += $size;

        return $negative !== 0 ? -$value : $value;
    }

    /**
     * pg_dump's string: a length (-1 = null), then the bytes.
     */
    public function str(int $intSize): ?string
    {
        $length = $this->int($intSize, true);

        if ($length === null || $length < 0 || $length > 1024 || $this->offset + $length > strlen($this->bytes)) {
            return null;
        }

        $value = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }

    public function cstring(): ?string
    {
        $end = strpos($this->bytes, "\0", $this->offset);

        if ($end === false) {
            return null;
        }

        $value = substr($this->bytes, $this->offset, $end - $this->offset);
        $this->offset = $end + 1;

        return $value;
    }

    /**
     * BSON string: int32 length (with the NUL), bytes, NUL.
     */
    public function bsonString(): ?string
    {
        $length = $this->int(4, false);

        if ($length === null || $length < 1 || $this->offset + $length > strlen($this->bytes)) {
            return null;
        }

        $value = substr($this->bytes, $this->offset, $length - 1);
        $this->offset += $length;

        return $value;
    }
}
