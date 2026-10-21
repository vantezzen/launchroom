<?php

declare(strict_types=1);

namespace App\Support\Dockerfiles;

use App\Enums\Dockerfiles\DockerfileConfidence;
use App\Exceptions\DockerfileModelFailed;

/**
 * A model's structured answer (docs/20 §4), validated: nothing here is
 * trusted until the linter and the verification build agree.
 */
final readonly class DockerfileDraft
{
    public const int MAX_DOCKERFILE_BYTES = 65536;

    public const int MAX_EXPLANATION = 2000;

    public function __construct(
        public string $dockerfile,
        public int $port,
        public ?string $startCommand,
        public string $explanation,
        public DockerfileConfidence $confidence,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}

    /**
     * The JSON schema of the structured output (`output_config.format`).
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'dockerfile' => ['type' => 'string', 'description' => 'The complete Dockerfile.'],
                'port' => ['type' => 'integer', 'description' => 'The TCP port the app listens on (also EXPOSEd).'],
                'startCommand' => ['type' => 'string', 'description' => 'The command the final stage runs, as a shell would print it.'],
                'explanation' => ['type' => 'string', 'description' => 'Two to four plain sentences for the developer: what the Dockerfile does and anything they should check.'],
                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            ],
            'required' => ['dockerfile', 'port', 'startCommand', 'explanation', 'confidence'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Strictly parses the model's JSON text.
     *
     * @throws DockerfileModelFailed
     */
    public static function fromJson(string $json, int $inputTokens = 0, int $outputTokens = 0): self
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DockerfileModelFailed('The model did not answer with valid JSON.', true, $inputTokens, $outputTokens);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new DockerfileModelFailed('The model did not answer with a JSON object.', true, $inputTokens, $outputTokens);
        }

        $dockerfile = $data['dockerfile'] ?? null;
        $port = $data['port'] ?? null;
        $start = $data['startCommand'] ?? null;
        $explanation = $data['explanation'] ?? null;
        $confidence = is_string($data['confidence'] ?? null) ? DockerfileConfidence::tryFrom($data['confidence']) : null;

        if (! is_string($dockerfile) || trim($dockerfile) === '' || strlen($dockerfile) > self::MAX_DOCKERFILE_BYTES || ! mb_check_encoding($dockerfile, 'UTF-8') || str_contains($dockerfile, "\0")) {
            throw new DockerfileModelFailed('The model’s Dockerfile was empty or too large.', true, $inputTokens, $outputTokens);
        }

        if (! is_int($port) || $port < 1 || $port > 65535) {
            throw new DockerfileModelFailed('The model did not name a valid port.', true, $inputTokens, $outputTokens);
        }

        if (! is_string($explanation) || $confidence === null || ($start !== null && ! is_string($start))) {
            throw new DockerfileModelFailed('The model’s answer was missing fields.', true, $inputTokens, $outputTokens);
        }

        $start = $start === null ? null : trim(mb_substr($start, 0, 4096));

        return new self(
            dockerfile: str_replace(["\r\n", "\r"], "\n", rtrim($dockerfile))."\n",
            port: $port,
            startCommand: $start === '' ? null : $start,
            explanation: trim(mb_substr($explanation, 0, self::MAX_EXPLANATION)),
            confidence: $confidence,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
        );
    }

    public function withDockerfile(string $dockerfile): self
    {
        return new self($dockerfile, $this->port, $this->startCommand, $this->explanation, $this->confidence, $this->inputTokens, $this->outputTokens);
    }
}
