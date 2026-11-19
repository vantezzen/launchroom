<?php

declare(strict_types=1);

namespace App\Enums\Backups;

/**
 * Why a destination test (or a backup to it) failed, so the page can say
 * what to fix instead of "Unreachable" for everything. Classified from the
 * masked, secret-free error text of restic or the endpoint check.
 */
enum DestinationErrorKind: string
{
    /** The key is wrong, revoked or can't read and write the bucket. */
    case WrongCredentials = 'wrong_credentials';
    /** The bucket holds a backup repository this password can't open. */
    case WrongRepositoryPassword = 'wrong_repository_password';
    case BucketMissing = 'bucket_missing';
    /** DNS, TLS, connection refused or timed out, or a blocked address. */
    case Unreachable = 'unreachable';
    case Other = 'other';

    public static function classify(?string $error): ?self
    {
        if ($error === null || trim($error) === '') {
            return null;
        }

        $text = mb_strtolower($error);

        return match (true) {
            self::contains($text, ['wrong password', 'no key found', 'repository password']) => self::WrongRepositoryPassword,
            self::contains($text, ['nosuchbucket', 'bucket does not exist', 'bucket not found', 'specified bucket does not exist']) => self::BucketMissing,
            self::contains($text, ['access denied', 'accessdenied', 'invalidaccesskeyid', 'signaturedoesnotmatch', '403 forbidden', 'forbidden', 'unauthorized', 'invalid access key', 'credentials could not be decrypted']) => self::WrongCredentials,
            self::contains($text, ['no such host', 'connection refused', 'timeout', 'timed out', 'i/o timeout', 'dial tcp', 'network is unreachable', 'certificate', 'tls', 'private network', 'not allowed', 'resolve']) => self::Unreachable,
            default => self::Other,
        };
    }

    /**
     * @param  list<string>  $needles
     */
    private static function contains(string $text, array $needles): bool
    {
        return array_any($needles, fn (string $needle): bool => str_contains($text, $needle));
    }
}
