<?php

declare(strict_types=1);

namespace App\Services\Dockerfiles;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\TextBlock;
use App\Contracts\DockerfileModel;
use App\Exceptions\DockerfileModelFailed;
use App\Models\Dockerfiles\AiProviderSettings;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use App\Support\Dockerfiles\DockerfileDraft;
use App\Support\Dockerfiles\DockerfilePrompt;
use Throwable;

/**
 * Claude through the official Anthropic PHP SDK (docs/20 §4): one
 * `messages.create` per attempt with adaptive thinking (the model's
 * default), `output_config.effort` and a JSON-schema structured output.
 *
 * The API key and base URL are passed explicitly, so the SDK never reads
 * ANTHROPIC_* environment variables or credential files. SDK exceptions are
 * mapped to user-safe messages and never chained (their request carries the
 * key header).
 */
final readonly class AnthropicDockerfileModel implements DockerfileModel
{
    public function __construct(
        private AiProviderSecrets $secrets,
        private AnthropicTransport $transport,
    ) {}

    public function draft(DockerfilePrompt $prompt): DockerfileDraft
    {
        $settings = AiProviderSettings::current();

        try {
            $key = $this->secrets->get($settings);
        } catch (DecryptionFailed) {
            throw new DockerfileModelFailed('The stored API key could not be decrypted. Set it again in Settings → Dockerfile generation.');
        }

        if ($key === null || $key === '') {
            throw new DockerfileModelFailed('No API key is set. Add one in Settings → Dockerfile generation.');
        }

        $client = new Client(
            apiKey: $key,
            authToken: '',
            webhookKey: '',
            baseUrl: config()->string('launchroom.dockerfile_generation.anthropic_url'),
            requestOptions: ['transporter' => $this->transport->client(), 'maxRetries' => 1],
            openTelemetry: false,
        );
        unset($key);

        try {
            $message = $client->messages->create(
                maxTokens: config()->integer('launchroom.dockerfile_generation.max_tokens'),
                messages: [['role' => 'user', 'content' => $prompt->user]],
                model: $prompt->model,
                metadata: ['userID' => $prompt->requester],
                outputConfig: [
                    'effort' => match (config()->string('launchroom.dockerfile_generation.effort')) {
                        'low' => 'low',
                        'medium' => 'medium',
                        'xhigh' => 'xhigh',
                        'max' => 'max',
                        default => 'high',
                    },
                    'format' => ['type' => 'json_schema', 'schema' => DockerfileDraft::schema()],
                ],
                system: $prompt->system,
            );
        } catch (AuthenticationException|PermissionDeniedException) {
            throw new DockerfileModelFailed('Anthropic refused the API key. Check it in Settings → Dockerfile generation.');
        } catch (NotFoundException) {
            throw new DockerfileModelFailed("The model {$prompt->model} is not available for this API key. Pick another one in Settings → Dockerfile generation.");
        } catch (RateLimitException) {
            throw new DockerfileModelFailed('Anthropic is rate limiting this API key. Try again in a minute.', true);
        } catch (BadRequestException $e) {
            throw new DockerfileModelFailed('Anthropic did not accept the request'.$this->reason($e).'.');
        } catch (APIStatusException $e) {
            throw new DockerfileModelFailed('Anthropic answered with an error ('.($e->status ?? 0).'). Try again in a minute.', true);
        } catch (APIConnectionException) {
            throw new DockerfileModelFailed('launchroom could not reach api.anthropic.com. Check the dashboard server’s internet access.', true);
        } catch (Throwable $e) {
            throw new DockerfileModelFailed('The request to Anthropic failed ('.class_basename($e).').', true);
        }

        $in = $message->usage->inputTokens;
        $out = $message->usage->outputTokens;

        if ($message->stopReason === 'refusal') {
            throw new DockerfileModelFailed('The model declined to write this Dockerfile.', false, $in, $out);
        }

        if ($message->stopReason === 'max_tokens') {
            throw new DockerfileModelFailed('The model ran out of room before finishing the Dockerfile.', true, $in, $out);
        }

        $text = '';

        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $text .= $block->text;
            }
        }

        return DockerfileDraft::fromJson($text, $in, $out);
    }

    /**
     * The provider's own error message (never echoes the key), shortened.
     */
    private function reason(APIStatusException $e): string
    {
        $body = $e->body ?? null;
        $message = is_array($body) && is_array($body['error'] ?? null) && is_string($body['error']['message'] ?? null) ? $body['error']['message'] : '';

        return $message === '' ? '' : ': '.mb_substr($message, 0, 200);
    }
}
