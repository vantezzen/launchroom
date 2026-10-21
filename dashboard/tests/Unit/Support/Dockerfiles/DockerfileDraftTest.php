<?php

declare(strict_types=1);

use App\Enums\Dockerfiles\DockerfileConfidence;
use App\Exceptions\DockerfileModelFailed;
use App\Support\Dockerfiles\DockerfileDraft;

test('structured output is parsed strictly', function (): void {
    $draft = DockerfileDraft::fromJson(json_encode([
        'dockerfile' => "FROM node:22\r\nCMD [\"node\"]",
        'port' => 3000,
        'startCommand' => ' node server.js ',
        'explanation' => 'Builds and runs it.',
        'confidence' => 'medium',
    ]), 10, 20);

    expect($draft->dockerfile)->toBe("FROM node:22\nCMD [\"node\"]\n")
        ->and($draft->port)->toBe(3000)
        ->and($draft->startCommand)->toBe('node server.js')
        ->and($draft->confidence)->toBe(DockerfileConfidence::Medium)
        ->and($draft->inputTokens)->toBe(10);
});

test('malformed answers are retryable failures', function (string $json): void {
    expect(fn (): DockerfileDraft => DockerfileDraft::fromJson($json))->toThrow(DockerfileModelFailed::class);
})->with([
    'not json' => ['here you go: FROM node'],
    'list' => ['[1,2]'],
    'no dockerfile' => ['{"port":3000,"startCommand":"x","explanation":"x","confidence":"high"}'],
    'bad port' => ['{"dockerfile":"FROM x","port":70000,"startCommand":"x","explanation":"x","confidence":"high"}'],
    'port as string' => ['{"dockerfile":"FROM x","port":"3000","startCommand":"x","explanation":"x","confidence":"high"}'],
    'bad confidence' => ['{"dockerfile":"FROM x","port":3000,"startCommand":"x","explanation":"x","confidence":"sure"}'],
]);

test('the schema is strict', function (): void {
    expect(DockerfileDraft::schema()['additionalProperties'])->toBeFalse()
        ->and(DockerfileDraft::schema()['required'])->toBe(['dockerfile', 'port', 'startCommand', 'explanation', 'confidence']);
});
