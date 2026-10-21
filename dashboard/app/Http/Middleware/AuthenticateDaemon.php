<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Servers\MarkServerSeen;
use App\Enums\ClientCertificateMode;
use App\Enums\ServerStatus;
use App\Http\Daemon\ClientCertificateIdentity;
use App\Models\Server;
use App\Services\Daemon\ClientCertificatePresence;
use App\Services\Daemon\Exceptions\InvalidRequestSignature;
use App\Services\Daemon\HttpMessageSignature;
use App\Services\Daemon\SignedRequest;
use App\Support\Crypto\PublicKeyFormat;
use App\Support\PublicId;
use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JsonException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates daemon requests by their RFC 9421 signature, exactly as in
 * protocol/SIGNING.md §2.4, and binds the server to the request. Fails closed
 * with a generic 401; the reason is only logged.
 *
 * Modes: `server` (default) resolves `keyid` to a non-revoked server (a
 * replaced server, revoked by "Replace server", may only GET its state: the
 * empty decommission NodeState that stops its workloads, docs/14 §5.3);
 * `enroll` accepts only keyid "enroll" and verifies with the signing key
 * from the request body (proof of possession). The join token is checked by
 * the enroll action.
 *
 * Client certificates (mTLS, D50, docs/03 §4.3): when the instance checks
 * them, the TLS identity the control-plane edge passed along must be a
 * certificate of the signing server ({@see ClientCertificateIdentity}); with
 * `require`, a request without one is refused unless it asks for a
 * certificate (the action decides), comes from the control-plane server
 * over its loopback, or is a replaced server fetching its decommission state.
 */
final readonly class AuthenticateDaemon
{
    public const string MODE_ENROLL = 'enroll';

    private const string SERVER_ATTRIBUTE = 'launchroom.daemon.server';

    private const string PRESENCE_ATTRIBUTE = 'launchroom.daemon.client_certificate';

    public function __construct(
        private MarkServerSeen $markServerSeen,
        private ClientCertificateIdentity $identity,
    ) {}

    /**
     * What the request showed at the TLS layer (docs/03 §4.3). Only valid behind this middleware.
     */
    public static function clientCertificate(Request $request): ClientCertificatePresence
    {
        $presence = $request->attributes->get(self::PRESENCE_ATTRIBUTE);

        return $presence instanceof ClientCertificatePresence ? $presence : ClientCertificatePresence::unchecked();
    }

    /**
     * The server that signed this request. Only valid behind this middleware.
     */
    public static function server(Request $request): Server
    {
        $server = $request->attributes->get(self::SERVER_ATTRIBUTE);

        if (! $server instanceof Server) {
            throw new LogicException('No authenticated daemon on this request.');
        }

        return $server;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $mode = 'server'): Response
    {
        try {
            $parsed = HttpMessageSignature::parse(
                $this->signedRequest($request),
                now()->getTimestamp(),
                config()->integer('launchroom.daemon.max_clock_skew_seconds'),
            );

            $server = null;

            if ($mode === self::MODE_ENROLL) {
                if ($parsed->keyId !== HttpMessageSignature::ENROLL_KEY_ID) {
                    throw InvalidRequestSignature::because('enroll requires keyid "enroll"');
                }

                $publicKey = $this->enrollingKey($request);
            } else {
                $server = $this->resolveServer($parsed->keyId);
                $publicKey = PublicKeyFormat::parseEd25519($server->signing_public_key)
                    ?? throw InvalidRequestSignature::because('stored key is malformed');
            }

            HttpMessageSignature::verify($parsed, $publicKey);

            // Replay protection after the signature checks out, so
            // unauthenticated requests can't write to the nonce store.
            $fresh = Cache::store($this->nonceStore())->add(
                "lr:nonce:{$parsed->keyId}:{$parsed->nonce}",
                1,
                config()->integer('launchroom.daemon.nonce_ttl_seconds'),
            );

            if (! $fresh) {
                throw InvalidRequestSignature::because('nonce was already used');
            }
        } catch (InvalidRequestSignature $e) {
            Log::info('daemon request rejected', ['reason' => $e->getMessage(), 'path' => $request->path(), 'ip' => $request->ip()]);

            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        if ($server instanceof Server && $server->status === ServerStatus::Revoked && ! $request->routeIs('daemon.state')) {
            Log::info('daemon request rejected', ['reason' => 'replaced server outside the decommission state', 'path' => $request->path(), 'ip' => $request->ip()]);

            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        if ($server instanceof Server) {
            $mode = ClientCertificateMode::current();

            try {
                $presence = $this->identity->resolve($request, $server, $mode);
            } catch (InvalidRequestSignature $e) {
                Log::info('daemon request rejected', ['reason' => $e->getMessage(), 'server' => $server->public_id, 'path' => $request->path(), 'ip' => $request->ip()]);

                return new JsonResponse(['error' => 'unauthorized'], 401);
            }

            if ($mode === ClientCertificateMode::Require && ! $presence->verified() && ! $this->mayGoWithoutCertificate($request, $server, $presence)) {
                Log::info('daemon request rejected', ['reason' => 'client certificate required', 'server' => $server->public_id, 'path' => $request->path(), 'ip' => $request->ip()]);
                // It proved its key; readiness notes the missing certificate.
                $this->markServerSeen->handle($server, $presence);

                return new JsonResponse(['error' => 'client certificate required'], 401);
            }

            $request->attributes->set(self::SERVER_ATTRIBUTE, $server);
            $request->attributes->set(self::PRESENCE_ATTRIBUTE, $presence);
            $this->markServerSeen->handle($server, $presence);
        }

        return $next($request);
    }

    /**
     * Requests that may go without a certificate while they are required:
     * asking for one (IssueClientCertificate applies its own rules), the
     * control-plane server's own launchroomd on its loopback (it never goes
     * through the edge), and a replaced server's decommission state (no
     * secrets in it, and it must stop its workloads even after its
     * certificate expired).
     */
    private function mayGoWithoutCertificate(Request $request, Server $server, ClientCertificatePresence $presence): bool
    {
        if ($request->routeIs('daemon.client-certificate')) {
            return true;
        }

        if ($server->is_control_plane && ! $presence->throughEdge) {
            return true;
        }

        return $server->status === ServerStatus::Revoked && $server->replaced_at !== null && $request->routeIs('daemon.state');
    }

    private function signedRequest(Request $request): SignedRequest
    {
        $headers = [];

        foreach (['signature-input', 'signature', 'content-digest', 'date', 'x-lr-nonce'] as $name) {
            $headers[$name] = array_values(array_filter($request->headers->all($name), is_string(...)));
        }

        $target = $request->server->get('REQUEST_URI');

        return new SignedRequest(
            $request->getMethod(),
            is_string($target) ? $target : '/',
            $headers,
            $request->getContent(),
        );
    }

    /**
     * @throws InvalidRequestSignature
     */
    private function resolveServer(string $keyId): Server
    {
        if (! PublicId::isValid($keyId, Server::publicIdPrefix())) {
            throw InvalidRequestSignature::because('unknown keyid');
        }

        $server = Server::query()
            ->where('public_id', $keyId)
            ->where(static fn (Builder $query) => $query
                ->where('status', '!=', ServerStatus::Revoked->value)
                // A replaced server's key only still fetches its empty decommission state (docs/14 §5.3).
                ->orWhere(static fn (Builder $replaced) => $replaced->whereNotNull('replaced_at')->where('status', ServerStatus::Revoked->value)))
            ->first();

        return $server ?? throw InvalidRequestSignature::because('unknown or revoked server');
    }

    /**
     * @throws InvalidRequestSignature
     */
    private function enrollingKey(Request $request): string
    {
        try {
            $body = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidRequestSignature::because('enroll body is not JSON');
        }

        $key = is_array($body) && is_string($body['signingKey'] ?? null)
            ? PublicKeyFormat::parseEd25519($body['signingKey'])
            : null;

        return $key ?? throw InvalidRequestSignature::because('enroll body has no valid signingKey');
    }

    private function nonceStore(): ?string
    {
        $store = config('launchroom.daemon.nonce_cache_store');

        return is_string($store) && $store !== '' ? $store : null;
    }
}
