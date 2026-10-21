<?php

declare(strict_types=1);

namespace App\Services\Dockerfiles;

use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;

/**
 * The HTTP transport of the Anthropic SDK (docs/20 §4): Guzzle without
 * proxies or redirects, bounded timeouts, TLS verification on. The base URL
 * is fixed by configuration, never user input, so SafeHttpClient's
 * resolve-and-pin isn't needed; tests replace this binding with a mock.
 */
class AnthropicTransport
{
    public function client(): ClientInterface
    {
        return new Client([
            'allow_redirects' => false,
            'proxy' => '',
            'verify' => true,
            'http_errors' => false,
            'connect_timeout' => config()->integer('launchroom.dockerfile_generation.connect_timeout_seconds'),
            'timeout' => config()->integer('launchroom.dockerfile_generation.timeout_seconds'),
        ]);
    }
}
