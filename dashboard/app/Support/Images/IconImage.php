<?php

declare(strict_types=1);

namespace App\Support\Images;

/**
 * A project icon after {@see IconSanitizer}: bytes we wrote ourselves, in one
 * of three formats with a fixed content type.
 */
final readonly class IconImage
{
    public const array CONTENT_TYPES = [
        'png' => 'image/png',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
    ];

    /**
     * @param  'png'|'webp'|'svg'  $extension
     */
    public function __construct(
        public string $bytes,
        public string $extension,
    ) {}

    public function contentType(): string
    {
        return self::CONTENT_TYPES[$this->extension];
    }

    /** Short content hash: the file name and the URL's cache buster. */
    public function version(): string
    {
        return substr(hash('sha256', $this->bytes), 0, 16);
    }
}
