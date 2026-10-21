<?php

declare(strict_types=1);

namespace App\Services\Registry;

use App\Data\Registry\IssuedRegistryToken;
use App\Data\Registry\RegistryScope;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Issues Docker token-spec bearer tokens for the launchroom registry (Zot,
 * docs/05 §6): ES256 JWTs with the `access` claim
 * `[{type: repository, name, actions}]`. Zot checks the signature (public key
 * file), the time claims and that the access claim names the exact
 * repository and action of each request. Authorization (which scopes a
 * caller may get) is decided by the actions in App\Actions\Registry.
 */
final class RegistryTokenIssuer
{
    /** Hard ceiling for any registry token's lifetime (docs/09 R24). */
    public const int MAX_TTL_SECONDS = 300;

    private ?RegistryTokenKey $key = null;

    /**
     * @param  list<RegistryScope>  $scopes
     */
    public function issue(string $subject, array $scopes, ?int $ttlSeconds = null): IssuedRegistryToken
    {
        $ttl = min($ttlSeconds ?? config()->integer('launchroom.registry.token_ttl_seconds'), self::MAX_TTL_SECONDS);
        $ttl = max($ttl, 1);
        $now = CarbonImmutable::now()->startOfSecond();
        $expires = $now->addSeconds($ttl);
        $key = $this->key();

        $token = $this->configuration($key)
            ->builder(ChainedFormatter::withUnixTimestampDates())
            ->issuedBy($this->setting('issuer'))
            ->permittedFor($this->setting('service'))
            ->relatedTo($subject !== '' ? $subject : 'anonymous')
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now->subSeconds(5))
            ->expiresAt($expires)
            ->withHeader('kid', $key->kid())
            ->withClaim('access', array_map(static fn (RegistryScope $scope): array => [
                'type' => 'repository',
                'name' => $scope->repository,
                'actions' => $scope->actions,
            ], $scopes))
            ->getToken(new Sha256, InMemory::plainText($key->privatePem()));

        return new IssuedRegistryToken($token->toString(), $now, $expires, $scopes);
    }

    /**
     * Verifies a token this issuer signed (signature, issuer, audience,
     * validity window) and returns it with its repository scopes; null if invalid.
     */
    public function verify(#[\SensitiveParameter] string $jwt): ?IssuedRegistryToken
    {
        if ($jwt === '' || strlen($jwt) > 8192) {
            return null;
        }

        try {
            $key = $this->key();
            $config = $this->configuration($key);
            $token = $config->parser()->parse($jwt);

            $clock = new class implements ClockInterface
            {
                public function now(): CarbonImmutable
                {
                    return CarbonImmutable::now();
                }
            };

            if (! $token instanceof Plain || ! $config->validator()->validate(
                $token,
                new SignedWith(new Sha256, InMemory::plainText($key->publicPem)),
                new IssuedBy($this->setting('issuer')),
                new PermittedFor($this->setting('service')),
                new StrictValidAt($clock),
            )) {
                return null;
            }

            $access = $token->claims()->get('access', []);
            $issuedAt = $token->claims()->get('iat');
            $expiresAt = $token->claims()->get('exp');
        } catch (Throwable) {
            return null;
        }

        if (! is_array($access)) {
            return null;
        }

        $scopes = [];

        foreach ($access as $entry) {
            if (! is_array($entry) || ($entry['type'] ?? null) !== 'repository' || ! is_string($entry['name'] ?? null) || ! is_array($entry['actions'] ?? null)) {
                continue;
            }

            $actions = array_values(array_filter($entry['actions'], is_string(...)));
            $scope = RegistryScope::fromScopeString('repository:'.$entry['name'].':'.implode(',', $actions));

            if ($scope instanceof RegistryScope) {
                $scopes[] = $scope;
            }
        }

        if (! $expiresAt instanceof DateTimeImmutable || ! $issuedAt instanceof DateTimeImmutable) {
            return null;
        }

        return new IssuedRegistryToken($jwt, CarbonImmutable::instance($issuedAt), CarbonImmutable::instance($expiresAt), $scopes);
    }

    /**
     * The signing key (read once per issuer).
     */
    public function key(): RegistryTokenKey
    {
        return $this->key ??= RegistryTokenKeyFile::read(config()->string('launchroom.registry.token_key_path'));
    }

    /**
     * @return non-empty-string
     */
    private function setting(string $name): string
    {
        $value = config()->string('launchroom.registry.'.$name);

        if ($value === '') {
            throw new \LogicException("launchroom.registry.{$name} must not be empty.");
        }

        return $value;
    }

    private function configuration(RegistryTokenKey $key): Configuration
    {
        return Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($key->privatePem()),
            InMemory::plainText($key->publicPem),
        );
    }
}
