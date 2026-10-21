<?php

declare(strict_types=1);

namespace App\Services\Dockerfiles;

use App\Contracts\ImageDigestResolver;
use App\Exceptions\ImageResolutionFailed;
use App\Support\Dockerfiles\DockerfileLint;
use App\Support\Images\ImageReference;

/**
 * Pins every external image of a linted Dockerfile to its digest
 * (docs/20 §4): `node:22-slim` → `node:22-slim@sha256:…`, resolved through
 * the registry mirror. A reference that doesn't resolve is a problem the
 * model can fix, never a silent `latest`.
 */
final readonly class DockerfilePinner
{
    public function __construct(
        private ImageDigestResolver $resolver,
    ) {}

    /**
     * @return array{dockerfile: string, problems: list<array{line: int, message: string}>}
     */
    public function pin(string $dockerfile, DockerfileLint $lint): array
    {
        $lines = explode("\n", $dockerfile);
        $problems = [];
        $digests = [];

        foreach ($lint->references as ['line' => $line, 'reference' => $reference]) {
            if (! array_key_exists($reference, $digests)) {
                $parsed = ImageReference::tryParse($reference);

                try {
                    $digests[$reference] = $parsed instanceof ImageReference ? $this->resolver->resolve($parsed)->digest : null;
                } catch (ImageResolutionFailed $e) {
                    $digests[$reference] = null;
                    $problems[] = ['line' => $line, 'message' => "{$reference} could not be found ({$e->getMessage()}). Use an image and tag that exist on Docker Hub or GHCR."];

                    continue;
                }
            }

            $digest = $digests[$reference];

            if ($digest === null) {
                continue;
            }

            $index = $line - 1;

            if (isset($lines[$index])) {
                $lines[$index] = (string) preg_replace(
                    '/(?<=\s|=)'.preg_quote($reference, '/').'(?=\s|$)/',
                    $reference.'@'.$digest,
                    $lines[$index],
                    1,
                );
            }
        }

        return ['dockerfile' => implode("\n", $lines), 'problems' => $problems];
    }
}
