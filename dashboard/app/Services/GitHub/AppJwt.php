<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use DateTimeImmutable;
use InvalidArgumentException;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use SensitiveParameter;

/**
 * The GitHub App JWT: RS256, `iss` = app id, `iat` 60 s in the past (GitHub's
 * recommendation against clock drift) and `exp` at most 10 minutes ahead.
 */
final class AppJwt
{
    public const int MAX_TTL_SECONDS = 600;

    public static function issue(int $appId, #[SensitiveParameter] string $privateKeyPem, DateTimeImmutable $now, int $ttlSeconds): string
    {
        if ($ttlSeconds < 60 || $ttlSeconds > self::MAX_TTL_SECONDS - 60) {
            throw new InvalidArgumentException('The App JWT lifetime must be between 1 and 9 minutes.');
        }

        if ($privateKeyPem === '') {
            throw new InvalidArgumentException('The GitHub App has no private key.');
        }

        // Whole seconds: the formatter writes floats for fractional
        // timestamps, which GitHub rejects.
        $base = (new DateTimeImmutable)->setTimestamp($now->getTimestamp());

        return Builder::new(new JoseEncoder, ChainedFormatter::default())
            ->issuedBy((string) $appId)
            ->issuedAt($base->modify('-60 seconds'))
            ->expiresAt($base->modify("+{$ttlSeconds} seconds"))
            ->getToken(new Sha256, InMemory::plainText($privateKeyPem))
            ->toString();
    }
}
