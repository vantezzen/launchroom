<?php

declare(strict_types=1);

namespace App\Console\Commands\Moves;

use App\Contracts\ControlPlaneDumps;
use App\Models\Server;
use App\Services\Backups\ControlPlaneKeyBundle;
use App\Services\Backups\RestoredKeyFiles;
use App\Services\Moves\HandoverStore;
use App\Services\Moves\MoveReceiverClient;
use App\Services\Moves\MoveToken;
use App\Support\Crypto\PublicKeyFormat;
use App\Support\PublicId;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * The new machine's half of Settings → Move launchroom (docs/14 §13.4),
 * run by `install.sh --move` in a one-off container of the new install's
 * dashboard image. Each step can be run again; state lives in --state
 * (`data/backups/move`, 0700, removed by the installer at the end).
 *
 * --join      claim the move with a fresh X25519 key pair, print the code
 *             to compare in the old dashboard, wait for the owner's
 *             approval, then write the join token and the control plane's
 *             key for this machine's launchroomd;
 * --handover  wait until the old dashboard handed over, fetch the final
 *             dump (each chunk signed, opened with the key sealed to this
 *             machine, digests checked) straight into pg_restore, and write
 *             the key bundle where the installer expects it (as
 *             `launchroom:restore` does);
 * --complete  tell the old dashboard the new one runs (it stays frozen
 *             and points browsers here);
 * --abort     tell it the hand-over failed (it takes changes again);
 * --prove-server=DIR
 *             this machine's launchroomd already enrolled with the old
 *             dashboard (its data folder DIR, mounted read-only): sign the
 *             claim with its identity key, so the move continues with that
 *             server instead of a join token it can't use (D103). The
 *             installer runs this step as root (the key is root's, 0600);
 *             it only writes the proof (no secret) to the state folder.
 *
 * The token comes from LR_MOVE_TOKEN (never argv) or the state folder.
 */
#[Signature('launchroom:move:receive
    {--join : Claim the move and wait for approval}
    {--handover : Wait for the hand-over, then restore the database and keys}
    {--complete= : Report that the new dashboard runs at this address}
    {--abort= : Report that the hand-over failed (reason)}
    {--prove-server= : Prove that this machine is the server whose launchroomd data folder this is}
    {--state=/data/backups/move : Folder for the move\'s state}
    {--keys-out= : Folder for the restored key files (default: <state>/restore)}
    {--url= : Reach the old dashboard at this address instead of the one in the token}
    {--ca-file= : Trust this CA bundle for the old dashboard\'s certificate}
    {--wait=86400 : Seconds to wait for approval or the hand-over}
    {--force : Restore into a database that already has tables}
    {--json : Machine-readable output}')]
#[Description('Receive a control-plane move on the new server (used by install.sh --move)')]
final class ReceiveMoveCommand extends Command
{
    private const int POLL_SECONDS = 3;

    /** PKCS#8 header of an Ed25519 private key (RFC 8410), followed by the 32-byte seed. */
    private const string PKCS8_ED25519 = "\x30\x2e\x02\x01\x00\x30\x05\x06\x03\x2b\x65\x70\x04\x22\x04\x20";

    public function handle(ControlPlaneDumps $database): int
    {
        try {
            return match (true) {
                $this->option('join') === true => $this->join(),
                $this->option('handover') === true => $this->handover($database),
                is_string($this->option('complete')) && $this->option('complete') !== '' => $this->reportComplete($this->option('complete')),
                is_string($this->option('abort')) && $this->option('abort') !== '' => $this->reportAbort($this->option('abort')),
                is_string($this->option('prove-server')) && $this->option('prove-server') !== '' => $this->proveServer($this->option('prove-server')),
                default => throw new RuntimeException('Pass --join, --handover, --complete=URL, --abort=REASON or --prove-server=DIR.'),
            };
        } catch (RuntimeException $e) {
            $this->fail($e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->fail('The move failed unexpectedly: '.$e::class);
        }
    }

    private function join(): int
    {
        $state = $this->state();
        [$public] = $this->keyPair();
        $client = $this->client($state);

        $proof = $this->serverProof($state['move'], $public);
        $claimed = $client->claim([
            'publicKey' => $public,
            'hostname' => $this->hostname(),
            'arch' => strtolower(php_uname('m')),
            'version' => config('launchroom.version') === null ? null : config()->string('launchroom.version'),
            ...($proof ?? []),
        ]);
        $code = is_string($claimed['code'] ?? null) ? $claimed['code'] : '';

        if (! hash_equals(MoveToken::claimCode($state['move'], $public), $code)) {
            throw new RuntimeException('The old dashboard showed another code than this machine computed. Nothing was changed; cancel the move there.');
        }

        $connected = is_string($claimed['joined'] ?? null) ? $claimed['joined'] : null;

        if ($proof !== null && $connected === null && ($claimed['status'] ?? null) === 'claimed') {
            // An older dashboard ignores the server and would issue a join
            // token this machine can't use (its launchroomd is enrolled).
            throw new RuntimeException('This machine is already a server of the old dashboard, and the old dashboard can’t continue the move with it (it runs an older release). Cancel the move there, update it, and start a new move.');
        }

        $this->say("This server’s code is {$code}.");

        if ($connected !== null) {
            $this->say("This machine is already connected to the old dashboard as {$connected}. The move continues with that server once you confirm the code.");
        }

        $this->say("Confirm it in the old dashboard: Settings → Move launchroom ({$state['url']}).");

        $deadline = time() + max(60, (int) $this->option('wait'));
        $announced = '';

        while (true) {
            $status = $client->status();
            $now = is_string($status['status'] ?? null) ? $status['status'] : '';

            if ($now === 'joining' && is_string($status['joinToken'] ?? null) && $connected === null) {
                $token = $this->open($status['joinToken']);
                $this->writeState('join-token', $token, 0600);
                $this->writeState('cp-key', $this->pinnedKey($state, $status)."\n", 0644);
                $this->result(['move' => $state['move'], 'code' => $code, 'url' => $state['url'], 'joined' => false]);

                return self::SUCCESS;
            }

            if (in_array($now, ['joined', 'moving', 'ready_to_hand_over', 'handing_over', 'handover_ready'], true)) {
                // Already enrolled (a re-run, or a server of the old dashboard
                // already): no new join token, only the pinned key.
                $this->writeState('cp-key', $this->pinnedKey($state, $status)."\n", 0644);
                File::delete($this->stateDir().'/join-token');
                $this->result(array_filter(['move' => $state['move'], 'code' => $code, 'url' => $state['url'], 'joined' => true, 'connected' => $connected]));

                return self::SUCCESS;
            }

            if ($now === 'cancelled' || $now === 'handed_over') {
                throw new RuntimeException('This move has ended in the old dashboard. Start a new one there.');
            }

            if ($now !== $announced && $now === 'claimed') {
                $this->say('Waiting for the code to be confirmed…');
            }

            $announced = $now;

            if (time() > $deadline) {
                throw new RuntimeException('The code wasn’t confirmed in time. Run the command again to keep waiting.');
            }

            Sleep::sleep(self::POLL_SECONDS);
        }
    }

    /**
     * Signs this move's claim with the identity key of the launchroomd whose
     * data folder is $dir (`enrollment.json` names the server, `keys/signing.key`
     * is its Ed25519 key, PKCS#8 PEM), and keeps the signature for --join.
     * The key itself is read here only and never written anywhere.
     */
    private function proveServer(string $dir): int
    {
        $state = $this->state();
        [$public] = $this->keyPair();
        $dir = rtrim($dir, '/');
        $enrollment = File::isFile($dir.'/enrollment.json') ? json_decode(File::get($dir.'/enrollment.json'), true) : null;
        $serverId = is_array($enrollment) && is_string($enrollment['serverId'] ?? null) ? $enrollment['serverId'] : '';

        if (preg_match(PublicId::regex(Server::publicIdPrefix()), $serverId) !== 1) {
            throw new RuntimeException("{$dir}/enrollment.json doesn’t name a server.");
        }

        $seed = $this->identitySeed($dir.'/keys/signing.key');
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $secret = sodium_crypto_sign_secretkey($keypair);
        $signature = sodium_crypto_sign_detached(MoveToken::serverProofMessage($state['move'], $serverId, $public), $secret);
        sodium_memzero($seed);
        sodium_memzero($secret);
        sodium_memzero($keypair);

        $this->writeState('server-proof', (string) json_encode(['move' => $state['move'], 'publicKey' => $public, 'serverId' => $serverId, 'serverProof' => base64_encode($signature)], JSON_UNESCAPED_SLASHES), 0644);
        $this->result(['move' => $state['move'], 'serverId' => $serverId]);

        return self::SUCCESS;
    }

    /**
     * The 32-byte Ed25519 seed of launchroomd's identity key: PKCS#8 DER of
     * an Ed25519 key is a fixed 16-byte header and the seed (RFC 8410).
     *
     * @return non-empty-string
     */
    private function identitySeed(string $file): string
    {
        $pem = File::isFile($file) ? File::get($file) : '';
        $der = preg_match('/-----BEGIN PRIVATE KEY-----([A-Za-z0-9+\/=\s]+)-----END PRIVATE KEY-----/', $pem, $match) === 1
            ? base64_decode((string) preg_replace('/\s+/', '', $match[1]), true)
            : false;
        sodium_memzero($pem);

        if (! is_string($der) || strlen($der) !== 48 || ! str_starts_with($der, self::PKCS8_ED25519)) {
            throw new RuntimeException("{$file} isn’t a launchroomd identity key this machine can read.");
        }

        $seed = substr($der, 16);
        sodium_memzero($der);

        if ($seed === '') {
            throw new RuntimeException("{$file} isn’t a launchroomd identity key this machine can read.");
        }

        return $seed;
    }

    /**
     * The proof --prove-server left for this move and key, as claim fields.
     *
     * @return array{serverId: string, serverProof: string}|null
     */
    private function serverProof(string $move, string $public): ?array
    {
        $file = $this->stateDir().'/server-proof';
        $proof = File::isFile($file) ? json_decode(File::get($file), true) : null;

        if (! is_array($proof) || ($proof['move'] ?? null) !== $move || ($proof['publicKey'] ?? null) !== $public
            || ! is_string($proof['serverId'] ?? null) || ! is_string($proof['serverProof'] ?? null)) {
            return null;
        }

        return ['serverId' => $proof['serverId'], 'serverProof' => $proof['serverProof']];
    }

    private function handover(ControlPlaneDumps $database): int
    {
        $state = $this->state();
        $client = $this->client($state);
        $deadline = time() + max(60, (int) $this->option('wait'));
        $announced = '';
        $manifest = null;

        while (true) {
            $status = $client->status();
            $now = is_string($status['status'] ?? null) ? $status['status'] : '';

            if ($now === 'handover_ready' && is_array($status['handover'] ?? null)) {
                $manifest = $status['handover'];

                break;
            }

            if ($now === 'cancelled') {
                throw new RuntimeException('The move was cancelled in the old dashboard. This server stays a regular server of it.');
            }

            if ($now === 'handed_over') {
                throw new RuntimeException('The old dashboard says the hand-over already completed. If this server doesn’t run the dashboard, start a new move there.');
            }

            if ($now !== $announced) {
                $this->say(match ($now) {
                    'joining', 'joined' => 'Joined. Move the apps and data in the old dashboard: Settings → Move launchroom.',
                    'moving' => 'Apps and data are moving to this server…',
                    'ready_to_hand_over' => 'Everything runs here. Hand over in the old dashboard to finish.',
                    'handing_over' => 'The old dashboard is taking its final dump…',
                    default => "Waiting ({$now})…",
                });
                $announced = $now;
            }

            if (time() > $deadline) {
                throw new RuntimeException('No hand-over yet. Run the command again to keep waiting.');
            }

            Sleep::sleep(self::POLL_SECONDS);
        }

        $key = $this->open(is_string($manifest['sealedKey'] ?? null) ? $manifest['sealedKey'] : '', base64: true);
        $dump = is_array($manifest['database'] ?? null) ? $manifest['database'] : [];
        $chunks = is_int($dump['chunks'] ?? null) ? $dump['chunks'] : 0;
        $digests = is_array($dump['digests'] ?? null) ? array_values(array_filter($dump['digests'], is_string(...))) : [];

        if ($chunks < 1 || count($digests) !== $chunks || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('The hand-over manifest is incomplete.');
        }

        // Keys first: they must open and match the pinned key before the database changes.
        $sealed = $client->keys();
        $keysMeta = is_array($manifest['keys'] ?? null) ? $manifest['keys'] : [];

        if (! hash_equals(is_string($keysMeta['sha256'] ?? null) ? $keysMeta['sha256'] : '', hash('sha256', $sealed))) {
            throw new RuntimeException('The key bundle doesn’t match the manifest.');
        }

        $keys = ControlPlaneKeyBundle::parse($this->open($sealed, base64: false));
        $this->assertSigningKey($keys, $state['signingKey']);
        $this->say('Keys received.');

        $this->prepareDatabase();
        $this->say('Restoring the dashboard’s database ('.number_format(is_int($dump['sizeBytes'] ?? null) ? $dump['sizeBytes'] : 0).' bytes)…');
        $raw = '';
        $bytes = $database->restore((function () use ($client, $state, $key, $chunks, $digests, &$raw): \Generator {
            for ($i = 0; $i < $chunks; $i++) {
                $plaintext = HandoverStore::open($state['move'], $i, $client->chunk($i), $key);

                if ($plaintext === null || ! hash_equals($digests[$i], hash('sha256', $plaintext))) {
                    throw new RuntimeException("Chunk {$i} of the dump is damaged. Nothing was changed on the old dashboard; run the command again.");
                }

                $raw .= (string) hex2bin($digests[$i]);

                yield $plaintext;
            }
        })(), null, config()->integer('launchroom.backups.task_timeout_seconds'));
        sodium_memzero($key);

        if (! hash_equals(is_string($dump['digest'] ?? null) ? $dump['digest'] : '', hash('sha256', $raw))) {
            throw new RuntimeException('The dump doesn’t match the manifest.');
        }

        $out = $this->keysOut();
        $written = RestoredKeyFiles::write($keys, $out, true);
        $this->say('Database restored.');

        $this->result([
            'move' => $state['move'],
            'databaseBytes' => $bytes,
            'keysOut' => $out,
            'files' => count($written),
            'appUrl' => is_string($manifest['appUrl'] ?? null) ? $manifest['appUrl'] : $keys['appUrl'],
            'canonicalUrl' => is_string($manifest['canonicalUrl'] ?? null) ? $manifest['canonicalUrl'] : null,
        ]);

        return self::SUCCESS;
    }

    private function reportComplete(string $url): int
    {
        $answer = $this->client($this->state())->complete($url);
        $this->result(['status' => $answer['status'] ?? null]);

        return self::SUCCESS;
    }

    private function reportAbort(string $reason): int
    {
        $answer = $this->client($this->state())->abort($reason);
        $this->result(['status' => $answer['status'] ?? null]);

        return self::SUCCESS;
    }

    /**
     * The move token (LR_MOVE_TOKEN, else the copy in the state folder) and
     * where to reach the old dashboard.
     *
     * @return array{url: string, move: string, secret: string, signingKey: string}
     */
    private function state(): array
    {
        $dir = $this->stateDir();
        $token = config('launchroom.moves.token');
        $file = $dir.'/token';

        if (is_string($token) && $token !== '') {
            $this->writeState('token', $token, 0600);
        } elseif (File::isFile($file)) {
            $token = trim(File::get($file));
        }

        $parsed = is_string($token) ? MoveToken::parse($token) : null;

        if ($parsed === null) {
            throw new RuntimeException('No valid move token. Copy the command from the old dashboard (Settings → Move launchroom) again.');
        }

        $url = $this->option('url');

        if (is_string($url) && $url !== '') {
            if (preg_match('/^https?:\/\/[A-Za-z0-9.:\[\]-]+$/D', $url) !== 1) {
                throw new RuntimeException('--url must be an address like https://dash.example.com.');
            }

            $parsed['url'] = rtrim($url, '/');
        }

        return $parsed;
    }

    /**
     * @param  array{url: string, move: string, secret: string, signingKey: string}  $state
     */
    private function client(array $state): MoveReceiverClient
    {
        $ca = $this->option('ca-file');

        if (is_string($ca) && $ca !== '' && ! File::isFile($ca)) {
            throw new RuntimeException("The CA file {$ca} can’t be read.");
        }

        return new MoveReceiverClient($state['url'], $state['move'], $state['secret'], $state['signingKey'], is_string($ca) && $ca !== '' ? $ca : null);
    }

    /**
     * This machine's X25519 key pair (made once, kept 0600 in the state folder).
     *
     * @return array{string, string} [public "x25519:…", key pair]
     */
    private function keyPair(): array
    {
        $file = $this->stateDir().'/receiver.key';

        if (File::isFile($file)) {
            $keypair = base64_decode(trim(File::get($file)), true);
        } else {
            $keypair = sodium_crypto_box_keypair();
            $this->writeState('receiver.key', base64_encode($keypair), 0600);
        }

        if (! is_string($keypair) || strlen($keypair) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
            throw new RuntimeException('The move’s key pair in the state folder is damaged. Remove the folder and start a new move.');
        }

        return ['x25519:'.base64_encode(sodium_crypto_box_publickey($keypair)), $keypair];
    }

    private function open(string $sealed, bool $base64 = true): string
    {
        [, $keypair] = $this->keyPair();
        $raw = $base64 ? base64_decode($sealed, true) : $sealed;
        $plaintext = is_string($raw) ? sodium_crypto_box_seal_open($raw, $keypair) : false;

        if (! is_string($plaintext)) {
            throw new RuntimeException('Something sealed to this machine doesn’t open with its key.');
        }

        return $plaintext;
    }

    /**
     * The key the token pinned; the old dashboard must still sign with it.
     *
     * @param  array{url: string, move: string, secret: string, signingKey: string}  $state
     * @param  array<string, mixed>  $status
     */
    private function pinnedKey(array $state, array $status): string
    {
        if (! is_string($status['cpKey'] ?? null) || ! hash_equals($state['signingKey'], $status['cpKey'])) {
            throw new RuntimeException('The old dashboard names another signing key than the move token.');
        }

        return $state['signingKey'];
    }

    /**
     * The restored signing key must be the one the token pinned (and every
     * answer was signed with): this is the same control plane.
     *
     * @param  array{appUrl: string|null, env: array<string, string>, files: array<string, string>, headscale: array<string, string>}  $keys
     */
    private function assertSigningKey(array $keys, string $pinned): void
    {
        $parts = explode(':', trim($keys['files']['signing.ed25519'] ?? ''));
        $seed = count($parts) === 3 ? base64_decode($parts[2], true) : false;

        if (! is_string($seed) || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new RuntimeException('The key bundle has no signing key.');
        }

        $public = PublicKeyFormat::formatEd25519(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)));

        if (! hash_equals($pinned, $public)) {
            throw new RuntimeException('The key bundle’s signing key isn’t the one in the move token. Nothing was restored.');
        }
    }

    private function prepareDatabase(): void
    {
        $tables = DB::selectOne("SELECT count(*) AS n FROM information_schema.tables WHERE table_schema = 'public'");
        $count = is_object($tables) && is_numeric($tables->n ?? null) ? (int) $tables->n : 0;

        if ($count > 0 && $this->option('force') !== true) {
            throw new RuntimeException("This server’s database already has {$count} tables. Pass --force to replace them (only on a new install).");
        }
    }

    private function hostname(): string
    {
        $configured = config('launchroom.moves.hostname');
        $name = is_string($configured) && $configured !== '' ? $configured : (string) gethostname();
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', $name) ?? 'new-server';

        return mb_substr($name === '' ? 'new-server' : $name, 0, 64);
    }

    private function stateDir(): string
    {
        $option = $this->option('state');
        $dir = rtrim(is_string($option) && $option !== '' ? $option : '/data/backups/move', '/');

        if (! File::isDirectory($dir)) {
            File::ensureDirectoryExists($dir, 0700);
            $this->ownLike($dir, dirname($dir));
        }

        return $dir;
    }

    private function keysOut(): string
    {
        $out = $this->option('keys-out');

        return is_string($out) && $out !== '' ? $out : $this->stateDir().'/restore';
    }

    private function writeState(string $name, string $contents, int $mode): void
    {
        $dir = $this->stateDir();
        $path = $dir.'/'.$name;
        File::put($path, $contents);
        @chmod($path, $mode);
        $this->ownLike($path, $dir);
    }

    /**
     * Run as root (--prove-server), files keep the owner of the folder they
     * are in, so the next step, which runs as the dashboard's user, can
     * read and replace them.
     */
    private function ownLike(string $path, string $reference): void
    {
        $owner = @fileowner($reference);

        if (is_int($owner) && @fileowner($path) !== $owner) {
            @chown($path, $owner);
            $group = @filegroup($reference);

            if (is_int($group)) {
                @chgrp($path, $group);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function result(array $result): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));
        }
    }

    private function say(string $message): void
    {
        // Progress goes to stderr, so --json output stays parseable.
        $this->output->getErrorStyle()->writeln($message);
    }
}
