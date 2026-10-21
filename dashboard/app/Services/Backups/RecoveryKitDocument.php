<?php

declare(strict_types=1);

namespace App\Services\Backups;

use App\Models\Backups\BackupDestination;
use RuntimeException;
use SensitiveParameter;

/**
 * The recovery kit file (docs/14 §4): plain text a human can read and
 * `launchroom:restore --kit=<file>` can parse. It holds everything needed
 * to reach the control-plane repository; the recovery passphrase is NOT in
 * it (the passphrase is the second factor that protects the key bundle).
 *
 * Machine-readable lines are `key: value` between the BEGIN/END markers.
 */
final class RecoveryKitDocument
{
    public const string BEGIN = '-----BEGIN LAUNCHROOM RECOVERY KIT-----';

    public const string END = '-----END LAUNCHROOM RECOVERY KIT-----';

    private const array KEYS = ['version', 'instance-url', 'endpoint', 'bucket', 'prefix', 'region', 'access-key-id', 'secret-access-key', 'repository-password', 'repository'];

    public static function render(BackupDestination $destination, DestinationCredentials $credentials, string $appUrl): string
    {
        $values = [
            'version' => '1',
            'instance-url' => $appUrl,
            'endpoint' => $destination->endpoint,
            'bucket' => $destination->bucket,
            'prefix' => $destination->prefix,
            'region' => $destination->region ?? '',
            'access-key-id' => $credentials->accessKeyId,
            'secret-access-key' => $credentials->secretAccessKey,
            'repository-password' => $credentials->repositoryPassword,
            'repository' => $destination->repository(),
        ];

        $lines = array_map(static fn (string $key, string $value): string => "{$key}: {$value}", array_keys($values), $values);
        $created = now()->utc()->format('Y-m-d H:i').' UTC';
        $begin = self::BEGIN;

        return <<<TXT
            launchroom recovery kit
            =======================

            Created {$created} for {$appUrl}.

            KEEP THIS FILE SAFE AND OFFLINE (a password manager or a printed copy).
            With it and your recovery passphrase, the control plane can be rebuilt
            on a new server after a total loss. The passphrase is not in this file:
            without it, nobody can decrypt your keys, even with this file.

            To restore on a fresh server:

              curl -fsSL https://launchroom.vantezzen.io/install | sh -s -- --restore

            The installer asks for this file and for your recovery passphrase, lists
            the control-plane snapshots, and restores the newest one. Details:
            docs/14-backup-and-restore.md §5.2.

            Manual restore into an existing installation (advanced):

              php artisan launchroom:restore --kit=<this file> --keys-out=<dir>

            A new kit is needed whenever the control-plane destination or the
            recovery passphrase changes.

            {$begin}
            TXT
            ."\n".implode("\n", $lines)."\n".self::END."\n";
    }

    /**
     * @return array<string, string> kit key => value
     */
    public static function parse(#[SensitiveParameter] string $document): array
    {
        $start = strpos($document, self::BEGIN);
        $end = strpos($document, self::END);

        if ($start === false || $end === false || $end < $start) {
            throw new RuntimeException('This is not a launchroom recovery kit (markers not found).');
        }

        $body = substr($document, $start + strlen(self::BEGIN), $end - $start - strlen(self::BEGIN));
        $values = [];

        foreach (explode("\n", $body) as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);

            if (in_array($key, self::KEYS, true)) {
                $values[$key] = trim($value);
            }
        }

        if (($values['version'] ?? null) !== '1') {
            throw new RuntimeException('This recovery kit has an unsupported version.');
        }

        return $values;
    }
}
