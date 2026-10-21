<?php

declare(strict_types=1);

namespace App\Data\Protocol\V1;

use App\Data\Protocol\ProtocolDocument;
use App\Data\Protocol\ProtocolJson;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Signed response to POST /daemon/v1/client-certificate
 * (protocol/schema/v1/client-certificate.json, docs/03 §4.3): the server's
 * certificate and the CAs the edge trusts, base64 DER.
 */
final class ClientCertificate extends Data implements ProtocolDocument
{
    /**
     * @param  list<string>  $caCertificates
     */
    public function __construct(
        public string $serverId,
        public string $certificate,
        public array $caCertificates,
        public CarbonImmutable $notBefore,
        public CarbonImmutable $notAfter,
        public CarbonImmutable $renewAfter,
    ) {}

    public function toProtocol(): array
    {
        return [
            'apiVersion' => self::API_VERSION,
            'kind' => 'ClientCertificate',
            'serverId' => $this->serverId,
            'certificate' => $this->certificate,
            'caCertificates' => $this->caCertificates,
            'notBefore' => ProtocolJson::time($this->notBefore),
            'notAfter' => ProtocolJson::time($this->notAfter),
            'renewAfter' => ProtocolJson::time($this->renewAfter),
        ];
    }
}
