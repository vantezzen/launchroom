<?php

declare(strict_types=1);

namespace App\Services\Daemon;

use App\Services\Daemon\Exceptions\InvalidRequestSignature;
use DateTimeImmutable;
use DateTimeZone;
use SensitiveParameter;

/**
 * The launchroom RFC 9421 profile for daemon → dashboard requests, exactly as
 * specified in protocol/SIGNING.md §2. Fail closed: every deviation throws.
 *
 * Verification covers steps 1–4, 6 and 7 of §2.4. The nonce (step 5) and
 * binding the server (step 8) need state and live in AuthenticateDaemon.
 */
final class HttpMessageSignature
{
    public const string ENROLL_KEY_ID = 'enroll';

    public const string TAG = 'launchroomd-v1';

    private const string COMPONENTS = '("@method" "@path" "@query" "content-digest" "date" "x-lr-nonce")';

    private const string SIGNATURE_INPUT_PATTERN = '/^sig1=(\("@method" "@path" "@query" "content-digest" "date" "x-lr-nonce"\);created=([0-9]{1,12});keyid="([A-Za-z0-9_]{1,64})";alg="ed25519";tag="launchroomd-v1")$/D';

    private const string SIGNATURE_PATTERN = '/^sig1=:([A-Za-z0-9+\/]{86}==):$/D';

    private const string DIGEST_PATTERN = '/^sha-256=:([A-Za-z0-9+\/]{43}=):$/D';

    private const string NONCE_PATTERN = '/^[A-Za-z0-9_-]{22}$/D';

    private const string DATE_FORMAT = 'D, d M Y H:i:s \G\M\T';

    private const array REQUIRED_HEADERS = ['signature-input', 'signature', 'content-digest', 'date', 'x-lr-nonce'];

    /**
     * Steps 1–4: parse and check everything that doesn't need the key.
     *
     * @throws InvalidRequestSignature
     */
    public static function parse(SignedRequest $request, int $now, int $maxSkewSeconds): ParsedSignature
    {
        foreach (self::REQUIRED_HEADERS as $name) {
            if (count($request->headers[$name] ?? []) !== 1) {
                throw InvalidRequestSignature::because("header {$name} must be present exactly once");
            }
        }

        $header = static fn (string $name): string => $request->headers[$name][0];

        if (preg_match(self::SIGNATURE_INPUT_PATTERN, $header('signature-input'), $input) !== 1) {
            throw InvalidRequestSignature::because('malformed Signature-Input');
        }

        if (preg_match(self::SIGNATURE_PATTERN, $header('signature'), $sig) !== 1) {
            throw InvalidRequestSignature::because('malformed Signature');
        }

        $signature = base64_decode($sig[1], true);

        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw InvalidRequestSignature::because('malformed Signature');
        }

        if (preg_match(self::DIGEST_PATTERN, $header('content-digest')) !== 1
            || ! hash_equals(self::contentDigest($request->body), $header('content-digest'))) {
            throw InvalidRequestSignature::because('content digest mismatch');
        }

        $date = self::parseDate($header('date'));
        $created = (int) $input[2];

        if ($date === null || $date !== $created) {
            throw InvalidRequestSignature::because('Date does not match created');
        }

        if (abs($now - $created) > $maxSkewSeconds) {
            throw InvalidRequestSignature::because('Date outside the allowed clock skew');
        }

        if (preg_match(self::NONCE_PATTERN, $header('x-lr-nonce')) !== 1) {
            throw InvalidRequestSignature::because('malformed nonce');
        }

        return new ParsedSignature(
            keyId: $input[3],
            nonce: $header('x-lr-nonce'),
            signature: $signature,
            base: self::signatureBase($request, $input[1]),
        );
    }

    /**
     * Step 7: verify against the resolved 32-byte public key.
     *
     * @throws InvalidRequestSignature
     */
    public static function verify(ParsedSignature $parsed, string $publicKey): void
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $parsed->signature === ''
            || ! sodium_crypto_sign_verify_detached($parsed->signature, $parsed->base, $publicKey)) {
            throw InvalidRequestSignature::because('signature does not verify');
        }
    }

    /**
     * Signs a request the way the daemon does (tests, tooling). Returns the
     * headers to add: Content-Digest, Date, X-LR-Nonce, Signature-Input, Signature.
     *
     * @return array<string, string>
     */
    public static function sign(
        string $method,
        string $requestTarget,
        string $body,
        string $keyId,
        #[SensitiveParameter] string $secretKey,
        int $created,
        string $nonceBytes,
    ): array {
        if ($secretKey === '') {
            throw new \InvalidArgumentException('Missing signing key.');
        }

        $headers = [
            'Content-Digest' => self::contentDigest($body),
            'Date' => gmdate(self::DATE_FORMAT, $created),
            'X-LR-Nonce' => rtrim(strtr(base64_encode($nonceBytes), '+/', '-_'), '='),
        ];

        $params = self::COMPONENTS.';created='.$created.';keyid="'.$keyId.'";alg="ed25519";tag="'.self::TAG.'"';
        $request = new SignedRequest($method, $requestTarget, [
            'content-digest' => [$headers['Content-Digest']],
            'date' => [$headers['Date']],
            'x-lr-nonce' => [$headers['X-LR-Nonce']],
        ], $body);

        $headers['Signature-Input'] = 'sig1='.$params;
        $headers['Signature'] = 'sig1=:'.base64_encode(sodium_crypto_sign_detached(self::signatureBase($request, $params), $secretKey)).':';

        return $headers;
    }

    public static function contentDigest(string $body): string
    {
        return 'sha-256=:'.base64_encode(hash('sha256', $body, true)).':';
    }

    /**
     * §2.3: one line per component, then @signature-params, LF-joined, no trailing newline.
     */
    public static function signatureBase(SignedRequest $request, string $signatureParams): string
    {
        $header = static fn (string $name): string => $request->headers[$name][0] ?? '';

        return implode("\n", [
            '"@method": '.strtoupper($request->method),
            '"@path": '.$request->path(),
            '"@query": '.$request->query(),
            '"content-digest": '.$header('content-digest'),
            '"date": '.$header('date'),
            '"x-lr-nonce": '.$header('x-lr-nonce'),
            '"@signature-params": '.$signatureParams,
        ]);
    }

    /**
     * Strict IMF-fixdate: must round-trip byte for byte.
     */
    private static function parseDate(string $value): ?int
    {
        $date = DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $value, new DateTimeZone('UTC'));

        if ($date === false || $date->format(self::DATE_FORMAT) !== $value) {
            return null;
        }

        return $date->getTimestamp();
    }
}
