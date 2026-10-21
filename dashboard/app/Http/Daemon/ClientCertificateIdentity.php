<?php

declare(strict_types=1);

namespace App\Http\Daemon;

use App\Enums\ClientCertificateMode;
use App\Models\Server;
use App\Models\ServerClientCertificate;
use App\Services\Daemon\ClientCertificatePresence;
use App\Services\Daemon\DaemonEdgeSecret;
use App\Services\Daemon\Exceptions\InvalidRequestSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Reads the TLS identity the control-plane edge passed along with a signed
 * daemon request (mTLS, D50, docs/03 §4.3) and binds it to the server whose
 * key signed the request. Fail closed: a wrong edge secret, a malformed
 * fingerprint, or a certificate that is unknown, revoked, expired or another
 * server's is a rejection, never "no certificate".
 */
final readonly class ClientCertificateIdentity
{
    /** Caddy sets it to `{http.request.tls.client.fingerprint}`. */
    public const string FINGERPRINT_HEADER = 'X-Lr-Client-Cert';

    /** Caddy sets it to the edge secret. */
    public const string EDGE_HEADER = 'X-Lr-Edge';

    /** What Caddy leaves when the connection has no client certificate. */
    public const string UNEXPANDED = '{http.request.tls.client.fingerprint}';

    public function __construct(
        private DaemonEdgeSecret $edgeSecret,
    ) {}

    /**
     * @throws InvalidRequestSignature
     */
    public function resolve(Request $request, Server $server, ClientCertificateMode $mode): ClientCertificatePresence
    {
        if (! $mode->checks()) {
            return ClientCertificatePresence::unchecked();
        }

        $edge = $this->single($request, self::EDGE_HEADER);

        if ($edge === null) {
            // Direct (loopback, a private path): nothing in the request is a TLS fact.
            return new ClientCertificatePresence(true, false);
        }

        if (! $this->edgeSecret->matches($edge)) {
            throw InvalidRequestSignature::because('edge secret mismatch');
        }

        $fingerprint = $this->single($request, self::FINGERPRINT_HEADER);

        if (in_array($fingerprint, [null, '', self::UNEXPANDED], true)) {
            return new ClientCertificatePresence(true, true);
        }

        if (preg_match('/^[0-9a-f]{64}$/D', $fingerprint) !== 1) {
            throw InvalidRequestSignature::because('malformed client certificate fingerprint');
        }

        $certificate = ServerClientCertificate::query()->where('fingerprint', $fingerprint)->first();

        if ($certificate === null) {
            throw InvalidRequestSignature::because('unknown client certificate');
        }

        if ($certificate->server_id !== $server->id) {
            Log::warning('daemon request with another server\'s client certificate', [
                'server' => $server->public_id,
                'certificateOf' => $certificate->server_id,
                'fingerprint' => $fingerprint,
                'ip' => $request->ip(),
            ]);

            throw InvalidRequestSignature::because('client certificate belongs to another server');
        }

        if ($certificate->revoked_at !== null) {
            throw InvalidRequestSignature::because('client certificate is revoked');
        }

        if (! $certificate->isActive()) {
            throw InvalidRequestSignature::because('client certificate is not valid now');
        }

        return new ClientCertificatePresence(true, true, $certificate);
    }

    /**
     * @throws InvalidRequestSignature
     */
    private function single(Request $request, string $name): ?string
    {
        $values = array_values(array_filter($request->headers->all(strtolower($name)), is_string(...)));

        if (count($values) > 1) {
            throw InvalidRequestSignature::because("{$name} is repeated");
        }

        return $values[0] ?? null;
    }
}
