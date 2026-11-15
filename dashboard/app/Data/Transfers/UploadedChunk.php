<?php

declare(strict_types=1);

namespace App\Data\Transfers;

use App\Support\Protocol\Grammar;
use Illuminate\Http\Request;
use SensitiveParameter;

/**
 * One chunk of an upload from the browser or the CLI (docs/14 §12.1): the raw
 * body with its SHA-256 in `X-Chunk-Sha256`.
 */
final readonly class UploadedChunk
{
    public const string DIGEST_HEADER = 'X-Chunk-Sha256';

    private function __construct(
        #[SensitiveParameter] public string $body,
        public string $sha256,
    ) {}

    /**
     * 422 without a well-formed digest.
     */
    public static function fromRequest(Request $request): self
    {
        $digest = strtolower((string) $request->header(self::DIGEST_HEADER, ''));
        abort_unless(preg_match(Grammar::HEX64, $digest) === 1, 422, 'Send the chunk’s SHA-256 in X-Chunk-Sha256.');

        return new self($request->getContent(), $digest);
    }
}
