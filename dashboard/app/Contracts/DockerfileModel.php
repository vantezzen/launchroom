<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Exceptions\DockerfileModelFailed;
use App\Support\Dockerfiles\DockerfileDraft;
use App\Support\Dockerfiles\DockerfilePrompt;

/**
 * Drafts a Dockerfile from a prompt with structured output (docs/20 §4):
 * App\Services\Dockerfiles\AnthropicDockerfileModel (the Claude API) or
 * App\Services\Dockerfiles\StubDockerfileModel (local/testing only).
 */
interface DockerfileModel
{
    /**
     * @throws DockerfileModelFailed
     */
    public function draft(DockerfilePrompt $prompt): DockerfileDraft;
}
