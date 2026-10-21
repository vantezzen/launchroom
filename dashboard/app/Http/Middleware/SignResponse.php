<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Crypto\Signer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Adds `X-LR-Signature: kid=…, sig=…` over the exact body bytes of every 2xx
 * daemon response with a body (protocol/SIGNING.md §3). Bodiless (204/304)
 * and error responses are not signed; the daemon never acts on them.
 *
 * Must stay the outermost middleware of the daemon group so nothing changes
 * the body after it is signed.
 */
final readonly class SignResponse
{
    public function __construct(
        private Signer $signer,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->isSuccessful()
            || $response instanceof StreamedResponse
            || $response instanceof BinaryFileResponse) {
            return $response;
        }

        $body = $response->getContent();

        if (is_string($body) && $body !== '') {
            $response->headers->set(Signer::HEADER, $this->signer->signatureHeader($body));
        }

        return $response;
    }
}
