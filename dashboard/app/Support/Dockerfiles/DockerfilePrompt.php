<?php

declare(strict_types=1);

namespace App\Support\Dockerfiles;

/**
 * One request to the model (docs/20 §4): the fixed system prompt, the user
 * message built from the scrubbed repository context, and who asks (an
 * opaque team hash for the provider's abuse tracking, no personal data).
 */
final readonly class DockerfilePrompt
{
    public function __construct(
        public string $system,
        public string $user,
        public string $model,
        public string $requester,
    ) {}
}
