<?php

declare(strict_types=1);

namespace App\Services\Alerts\Senders;

use App\Services\Alerts\DeliveryFailed;
use App\Services\Http\Exceptions\ResponseTooLarge;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\SafeHttpClient;
use Illuminate\Http\Client\ConnectionException;
use SensitiveParameter;
use Throwable;

/**
 * One JSON POST through SafeHttpClient (docs/09 R5) with errors mapped to
 * safe messages: the URL (and its token) never ends up in an error, a log
 * line or the database.
 */
final readonly class HttpPost
{
    public function __construct(
        private SafeHttpClient $http,
    ) {}

    /**
     * @param  array<string, string>  $headers
     *
     * @throws DeliveryFailed
     */
    public function send(#[SensitiveParameter] string $url, string $body, array $headers = []): void
    {
        try {
            $response = $this->http->postRaw($url, $body, 'application/json', $headers);
        } catch (UnsafeUrl) {
            throw DeliveryFailed::because('This address is not allowed: it must be https and reachable on the public internet.');
        } catch (ResponseTooLarge) {
            throw DeliveryFailed::because('The service answered with an unexpectedly large response.');
        } catch (Throwable $e) {
            if ($e instanceof ConnectionException) {
                throw DeliveryFailed::because('Could not connect to '.$this->host($url).'.');
            }

            throw DeliveryFailed::because('Sending to '.$this->host($url).' failed.');
        }

        if (! $response->successful()) {
            throw DeliveryFailed::status($response->status());
        }
    }

    private function host(#[SensitiveParameter] string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : 'the address';
    }
}
