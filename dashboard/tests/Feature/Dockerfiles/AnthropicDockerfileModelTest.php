<?php

declare(strict_types=1);

use App\Exceptions\DockerfileModelFailed;
use App\Models\Dockerfiles\AiProviderSettings;
use App\Services\Dockerfiles\AiProviderSecrets;
use App\Services\Dockerfiles\AnthropicDockerfileModel;
use App\Services\Dockerfiles\AnthropicTransport;
use App\Support\Dockerfiles\DockerfilePrompt;
use App\Support\Dockerfiles\DockerfilePromptBuilder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Tests\Support\Projects;

const AM_KEY = 'sk-ant-api03-test-key-0123456789abcdefghijklmnopqrstuvwxyz';

/**
 * @param  list<Response>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function amFake(array $responses, array &$history): void
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack]);

    app()->instance(AnthropicTransport::class, new class($client) extends AnthropicTransport
    {
        public function __construct(private readonly Client $client) {}

        public function client(): ClientInterface
        {
            return $this->client;
        }
    });
}

function amMessage(array $answer, string $stop = 'end_turn'): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'id' => 'msg_01', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
        'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'text', 'text' => json_encode($answer)]],
        'stop_reason' => $stop, 'stop_sequence' => null,
        'usage' => ['input_tokens' => 4200, 'output_tokens' => 900],
    ]));
}

function amPrompt(string $user = 'the app'): DockerfilePrompt
{
    return new DockerfilePrompt(DockerfilePromptBuilder::SYSTEM, $user, 'claude-opus-5-5', hash('sha256', 'team'));
}

beforeEach(function (): void {
    Projects::useTestKek();
    $settings = AiProviderSettings::current();
    resolve(AiProviderSecrets::class)->put($settings, AM_KEY);
    $settings->save();
});

test('one structured-output request to api.anthropic.com with the stored key', function (): void {
    $history = [];
    amFake([amMessage(['dockerfile' => "FROM node:22\nEXPOSE 3000\nCMD [\"node\",\"s.js\"]", 'port' => 3000, 'startCommand' => 'node s.js', 'explanation' => 'Runs it.', 'confidence' => 'high'])], $history);

    $draft = resolve(AnthropicDockerfileModel::class)->draft(amPrompt('package.json says express'));

    expect($draft->port)->toBe(3000)
        ->and($draft->inputTokens)->toBe(4200)
        ->and($draft->outputTokens)->toBe(900)
        ->and($history)->toHaveCount(1);

    $request = $history[0]['request'];
    $body = json_decode((string) $request->getBody(), true);

    expect((string) $request->getUri())->toBe('https://api.anthropic.com/v1/messages')
        ->and($request->getHeaderLine('x-api-key'))->toBe(AM_KEY)
        ->and($request->getHeaderLine('Authorization'))->toBe('')
        ->and($body['model'])->toBe('claude-opus-5-5')
        ->and($body['max_tokens'])->toBe(16000)
        ->and($body['output_config']['effort'])->toBe('high')
        ->and($body['output_config']['format']['type'])->toBe('json_schema')
        ->and($body['output_config']['format']['schema']['required'])->toContain('dockerfile')
        ->and($body)->not->toHaveKey('thinking')
        ->and($body['metadata']['user_id'])->toBe(hash('sha256', 'team'))
        ->and($body['messages'][0]['content'])->toBe('package.json says express')
        // The key is never part of what is sent besides its header.
        ->and((string) $request->getBody())->not->toContain(AM_KEY);
});

test('errors become user-safe messages without the key', function (int $status, string $type, string $message, bool $retryable): void {
    $history = [];
    amFake([new Response($status, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => 'details']])), new Response($status, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => 'details']]))], $history);

    try {
        resolve(AnthropicDockerfileModel::class)->draft(amPrompt());
        $this->fail('Expected a failure.');
    } catch (DockerfileModelFailed $e) {
        expect($e->getMessage())->toContain($message)->not->toContain(AM_KEY)
            ->and($e->retryable)->toBe($retryable);
    }
})->with([
    'bad key' => [401, 'authentication_error', 'refused the API key', false],
    'unknown model' => [404, 'not_found_error', 'not available for this API key', false],
    'rate limited' => [429, 'rate_limit_error', 'rate limiting', true],
    'bad request' => [400, 'invalid_request_error', 'did not accept the request: details', false],
]);

test('refusals and truncated answers are failed attempts', function (string $stop, bool $retryable): void {
    $history = [];
    amFake([amMessage(['dockerfile' => 'FROM x'], $stop)], $history);

    try {
        resolve(AnthropicDockerfileModel::class)->draft(amPrompt());
        $this->fail('Expected a failure.');
    } catch (DockerfileModelFailed $e) {
        expect($e->retryable)->toBe($retryable)->and($e->inputTokens)->toBe(4200);
    }
})->with([
    'refusal' => ['refusal', false],
    'max tokens' => ['max_tokens', true],
]);

test('without a key nothing is sent', function (): void {
    $settings = AiProviderSettings::current();
    resolve(AiProviderSecrets::class)->forget($settings);
    $settings->save();
    $history = [];
    amFake([], $history);

    expect(fn () => resolve(AnthropicDockerfileModel::class)->draft(amPrompt()))->toThrow(DockerfileModelFailed::class, 'No API key');
    expect($history)->toBe([]);
});
