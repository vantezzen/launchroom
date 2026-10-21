<?php

declare(strict_types=1);

namespace App\Services\Dockerfiles;

use App\Contracts\DockerfileModel;
use App\Exceptions\DockerfileModelFailed;
use App\Support\Dockerfiles\DockerfileDraft;
use App\Support\Dockerfiles\DockerfilePrompt;

/**
 * A fixed answer for the dev stack and tests (docs/20 §8): reads the JSON
 * file `launchroom.dockerfile_generation.stub_response` (the same shape the
 * real model returns). Only bound in local/testing; anywhere else it refuses,
 * so a misconfigured production install never pretends to have a model.
 */
final readonly class StubDockerfileModel implements DockerfileModel
{
    public function draft(DockerfilePrompt $prompt): DockerfileDraft
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new DockerfileModelFailed('The stub model only runs in development.');
        }

        $path = config('launchroom.dockerfile_generation.stub_response');

        if (! is_string($path) || $path === '' || ! is_file($path) || filesize($path) > 262144) {
            throw new DockerfileModelFailed('No stub response is configured (LAUNCHROOM_DOCKERFILE_STUB_RESPONSE).');
        }

        $json = (string) file_get_contents($path);

        return DockerfileDraft::fromJson($json, (int) ceil(strlen($prompt->system.$prompt->user) / 4), (int) ceil(strlen($json) / 4));
    }
}
