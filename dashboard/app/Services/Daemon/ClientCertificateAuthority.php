<?php

declare(strict_types=1);

namespace App\Services\Daemon;

use App\Data\Daemon\IssuedClientCertificate;
use App\Models\DaemonClientCa;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\PrivateKey;
use phpseclib3\Crypt\EC\PublicKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\File\X509;
use RuntimeException;
use SensitiveParameter;

/**
 * The launchroom-internal CA for server client certificates (mTLS, D50,
 * docs/03 §4.3, protocol/SIGNING.md §6). ECDSA P-256 throughout; the CA key
 * is envelope-encrypted (KEK-wrapped data key, AD `daemon_client_ca|<id>|key`)
 * and opened only here, to sign. Leaves are issued for public keys launchroomd
 * generated itself: their private keys never reach the control plane.
 */
final readonly class ClientCertificateAuthority
{
    /** SAN URI of a server certificate: `urn:launchroom:server:<server id>`. */
    public const string URI_PREFIX = 'urn:launchroom:server:';

    /** At most this many CAs are trusted at once (the protocol's 1–4). */
    public const int MAX_TRUSTED = 4;

    private const int CA_YEARS = 10;

    /** Advisory lock key for creating the CA ("launchroom ca"). */
    private const int LOCK_KEY = 0x6C72_6361_0001;

    public function __construct(
        private Envelope $envelope,
    ) {}

    /**
     * The CA that issues new certificates: the newest one not retired,
     * created on first use.
     */
    public function current(): DaemonClientCa
    {
        $ca = $this->newest();

        if ($ca instanceof DaemonClientCa) {
            return $ca;
        }

        return DB::transaction(function (): DaemonClientCa {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            return $this->newest() ?? $this->create();
        });
    }

    /**
     * Base64 DER of every CA the edge trusts (newest first, 1–4), creating
     * the first one if needed.
     *
     * @return list<string>
     */
    public function trusted(): array
    {
        $this->current();

        /** @var list<string> */
        return DaemonClientCa::query()
            ->whereNull('retired_at')
            ->where('not_after', '>', now())
            ->orderByDesc('id')
            ->limit(self::MAX_TRUSTED)
            ->get()
            ->map(static fn (DaemonClientCa $ca): string => $ca->derBase64())
            ->values()
            ->all();
    }

    /**
     * Normalizes and checks a daemon's public key: standard base64 of a DER
     * SubjectPublicKeyInfo of an ECDSA P-256 key. Returns the PEM, or null.
     */
    public static function publicKeyPem(string $base64): ?string
    {
        if (strlen($base64) > 512 || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $base64) !== 1) {
            return null;
        }

        $der = base64_decode($base64, true);

        if ($der === false || $der === '') {
            return null;
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);

        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
            || ! is_array($details['ec'] ?? null) || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            return null;
        }

        // Re-encoded from the parsed key, so trailing garbage or odd encodings never reach a certificate.
        return is_string($details['key'] ?? null) ? $details['key'] : null;
    }

    /**
     * Signs a client certificate for $server's public key (PEM from
     * {@see self::publicKeyPem()}).
     */
    public function issue(Server $server, string $publicKeyPem, CarbonImmutable $notBefore, CarbonImmutable $notAfter): IssuedClientCertificate
    {
        $ca = $this->current();
        $subjectKey = PublicKeyLoader::loadPublicKey($publicKeyPem);

        if (! $subjectKey instanceof PublicKey) {
            throw new InvalidArgumentException('The server key is not an EC public key.');
        }

        $issuer = new X509;
        $issuer->loadX509($ca->certificate);
        $issuer->setPrivateKey($this->caKey($ca));

        $subject = new X509;
        $subject->setPublicKey($subjectKey);
        $subject->setDN(['id-at-commonName' => $server->public_id, 'id-at-organizationName' => 'launchroom']);

        $serial = $this->serial();
        $signer = new X509;
        $signer->setStartDate($notBefore->toDateTimeImmutable());
        $signer->setEndDate($notAfter->toDateTimeImmutable());
        $signer->setSerialNumber($serial, 16);

        $leaf = new X509;
        $leaf->loadX509($this->encode($signer, $this->signed($signer, $issuer, $subject)));
        $leaf->setExtension('id-ce-basicConstraints', [], true);
        $leaf->setExtension('id-ce-keyUsage', ['digitalSignature'], true);
        $leaf->setExtension('id-ce-extKeyUsage', ['id-kp-clientAuth']);
        $leaf->setExtension('id-ce-subjectAltName', [['uniformResourceIdentifier' => self::URI_PREFIX.$server->public_id]]);
        $der = $this->encode($leaf, $this->signed($leaf, $issuer, $leaf), X509::FORMAT_DER);

        return new IssuedClientCertificate(
            der: $der,
            fingerprint: hash('sha256', $der),
            serial: $serial,
            notBefore: $notBefore,
            notAfter: $notAfter,
            caId: $ca->id,
        );
    }

    private function newest(): ?DaemonClientCa
    {
        return DaemonClientCa::query()
            ->whereNull('retired_at')
            ->where('not_after', '>', now()->addYear())
            ->orderByDesc('id')
            ->first();
    }

    private function create(): DaemonClientCa
    {
        $key = EC::createKey('secp256r1');
        $public = $key->getPublicKey();

        if (! $public instanceof PublicKey) {
            throw new RuntimeException('Could not create the client certificate CA key.');
        }

        $notBefore = now()->toImmutable()->subMinutes(5)->startOfSecond();
        $notAfter = $notBefore->addYears(self::CA_YEARS);

        $dn = [
            'id-at-commonName' => 'launchroom server CA',
            'id-at-organizationName' => 'launchroom',
            'id-at-organizationalUnitName' => InstanceSettings::current()->instance_id ?? 'launchroom',
        ];

        $subject = new X509;
        $subject->setPublicKey($public);
        $subject->setDN($dn);
        $subject->setKeyIdentifier($this->keyIdentifier($public));

        $issuer = new X509;
        $issuer->setPrivateKey($key);
        $issuer->setDN($dn);
        $issuer->setKeyIdentifier($this->keyIdentifier($public));

        $signer = new X509;
        $signer->setStartDate($notBefore->toDateTimeImmutable());
        $signer->setEndDate($notAfter->toDateTimeImmutable());
        $signer->setSerialNumber($this->serial(), 16);

        $ca = new X509;
        $ca->loadX509($this->encode($signer, $this->signed($signer, $issuer, $subject)));
        $ca->setExtension('id-ce-basicConstraints', ['cA' => true, 'pathLenConstraint' => 0], true);
        $ca->setExtension('id-ce-keyUsage', ['keyCertSign', 'cRLSign'], true);
        $signed = $this->signed($ca, $issuer, $ca);
        $pem = $this->encode($ca, $signed);
        $der = $this->encode($ca, $signed, X509::FORMAT_DER);

        $row = DaemonClientCa::query()->create([
            'certificate' => $pem,
            'fingerprint' => hash('sha256', $der),
            'private_key' => '',
            'wrapped_dek' => '',
            'kek_id' => $this->envelope->kekId(),
            'not_before' => $notBefore,
            'not_after' => $notAfter,
        ]);

        $this->sealKey($row, $key->toString('PKCS8'));
        $row->save();

        return $row;
    }

    /**
     * Encrypts the CA key onto the row (which must have its id). A new data key each time.
     */
    private function sealKey(DaemonClientCa $ca, #[SensitiveParameter] string $pem): void
    {
        $dek = $this->envelope->generateDataKey();
        $ca->wrapped_dek = $this->envelope->wrap($dek, self::wrapContext($ca));
        $ca->kek_id = $this->envelope->kekId();
        $ca->private_key = $dek->encrypt($pem, $this->associatedData($ca));
    }

    /**
     * @throws DecryptionFailed
     */
    private function caKey(DaemonClientCa $ca): PrivateKey
    {
        $dek = $this->envelope->unwrap($ca->wrapped_dek, self::wrapContext($ca));
        $key = PublicKeyLoader::loadPrivateKey($dek->decrypt($ca->private_key, $this->associatedData($ca)));

        if (! $key instanceof PrivateKey) {
            throw DecryptionFailed::malformed();
        }

        return $key;
    }

    /**
     * The KEK wrap context of a CA's data key (rotate-kek re-wraps it).
     */
    public static function wrapContext(DaemonClientCa $ca): string
    {
        return 'daemon_client_ca:'.$ca->id;
    }

    private function associatedData(DaemonClientCa $ca): string
    {
        return 'daemon_client_ca|'.$ca->id.'|key';
    }

    /**
     * 16 random bytes, top bit cleared (a positive serial), as lowercase hex.
     */
    private function serial(): string
    {
        $bytes = random_bytes(16);
        $bytes[0] = chr(ord($bytes[0]) & 0x7F | 0x01);

        return bin2hex($bytes);
    }

    /**
     * RFC 7093 §2 method 1: the leftmost 160 bits of the SHA-256 of the
     * subjectPublicKey bits.
     */
    private function keyIdentifier(PublicKey $key): string
    {
        $der = $key->toString('PKCS8');
        $raw = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $der) ?? '', true);

        // The uncompressed P-256 point is the last 65 bytes of the SPKI.
        return substr(hash('sha256', substr(is_string($raw) ? $raw : '', -65), true), 0, 20);
    }

    /**
     * @return array<string, mixed>
     */
    private function signed(X509 $signer, X509 $issuer, X509 $subject): array
    {
        $result = $signer->sign($issuer, $subject);

        if (! is_array($result)) {
            throw new RuntimeException('Could not sign the certificate.');
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * @param  array<string, mixed>  $certificate
     */
    private function encode(X509 $x509, array $certificate, int $format = X509::FORMAT_PEM): string
    {
        $encoded = $x509->saveX509($certificate, $format);

        if ($encoded === '') {
            throw new RuntimeException('Could not encode the certificate.');
        }

        return $encoded;
    }
}
