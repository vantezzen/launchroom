<?php

declare(strict_types=1);

namespace App\Services\Http;

use App\Enums\Http\WebhookPostFailure;
use App\Services\Http\Exceptions\ResponseTooLarge;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\Exceptions\WebhookPostFailed;
use App\Support\Webhooks\WebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use InvalidArgumentException;
use SensitiveParameter;
use Throwable;

/**
 * Outbound webhooks (alert channels docs/15 §3.5, audit sinks docs/09 R34):
 * one JSON POST through SafeHttpClient (docs/09 R5), a 2xx answer is a
 * delivery. Signed posts carry `X-Launchroom-Event`, `X-Launchroom-Delivery`
 * and `X-Launchroom-Signature` over the exact body bytes.
 */
final readonly class SignedWebhookPoster
{
    public function __construct(
        private SafeHttpClient $http,
    ) {}

    /**
     * @throws WebhookPostFailed
     */
    public function post(
        #[SensitiveParameter] string $url,
        string $body,
        string $event,
        string $deliveryId,
        #[SensitiveParameter] string $signingSecret,
        bool $allowPrivateNetwork = false,
    ): void {
        if ($signingSecret === '') {
            // Fail closed: never send an unsigned event.
            throw new InvalidArgumentException('A signed webhook needs a signing secret.');
        }

        $this->postUnsigned($url, $body, [
            'X-Launchroom-Event' => $event,
            'X-Launchroom-Delivery' => $deliveryId,
            'X-Launchroom-Signature' => WebhookSignature::header($signingSecret, CarbonImmutable::now()->getTimestamp(), $body),
        ], $allowPrivateNetwork);
    }

    /**
     * A POST to a service that authenticates by its URL (Slack, Discord).
     *
     * @param  array<string, string>  $headers
     *
     * @throws WebhookPostFailed
     */
    public function postUnsigned(#[SensitiveParameter] string $url, string $body, array $headers = [], bool $allowPrivateNetwork = false): void
    {
        $http = $allowPrivateNetwork ? $this->http->withPrivateNetwork() : $this->http;

        try {
            $response = $http->postRaw($url, $body, 'application/json', $headers);
        } catch (UnsafeUrl) {
            throw new WebhookPostFailed(WebhookPostFailure::UnsafeUrl, $this->host($url));
        } catch (ResponseTooLarge) {
            throw new WebhookPostFailed(WebhookPostFailure::ResponseTooLarge, $this->host($url));
        } catch (Throwable $e) {
            throw new WebhookPostFailed($e instanceof ConnectionException ? WebhookPostFailure::Unreachable : WebhookPostFailure::Failed, $this->host($url));
        }

        if (! $response->successful()) {
            throw new WebhookPostFailed(WebhookPostFailure::Status, $this->host($url), $response->status());
        }
    }

    private function host(#[SensitiveParameter] string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : 'the address';
    }
}
