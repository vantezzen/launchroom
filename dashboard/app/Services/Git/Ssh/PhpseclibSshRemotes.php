<?php

declare(strict_types=1);

namespace App\Services\Git\Ssh;

use App\Contracts\Git\SshRemotes;
use App\Services\Git\Exceptions\GitConnectionUnusable;
use App\Services\Http\Exceptions\UnsafeUrl;
use App\Services\Http\UrlGuard;
use App\Support\Git\HostKey;
use App\Support\Git\RefAdvertisement;
use App\Support\Git\SshUrl;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Net\SSH2;
use SensitiveParameter;
use Throwable;

/**
 * {@see SshRemotes} with phpseclib3 (pure PHP, no `ssh` binary).
 *
 * The host is checked like any user-influenced destination (docs/09 R5:
 * public addresses only unless an instance owner allowed a private
 * network), resolved once, and the socket opened to that IP, so DNS can't
 * change between the check and the connection.
 */
final readonly class PhpseclibSshRemotes implements SshRemotes
{
    private const int TIMEOUT_SECONDS = 10;

    /** Advertisements are small; cap what we read from a hostile server. */
    private const int MAX_ADVERTISEMENT_BYTES = 4 * 1024 * 1024;

    /** Host key algorithms to ask for, one connection each, and the key type each yields. */
    private const array ALGORITHMS = [
        'ssh-ed25519' => 'ssh-ed25519',
        'ecdsa-sha2-nistp256' => 'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384' => 'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521' => 'ecdsa-sha2-nistp521',
        'rsa-sha2-512' => 'ssh-rsa',
    ];

    public function __construct(
        private UrlGuard $guard,
    ) {}

    public function hostKeys(SshUrl $url, bool $privateNetwork): array
    {
        $keys = [];

        foreach (self::ALGORITHMS as $algorithm => $type) {
            // An unreachable server throws here, on the first algorithm.
            $ssh = $this->connect($url, $privateNetwork, [$algorithm]);

            try {
                $line = $ssh->getServerPublicHostKey();
            } catch (Throwable) {
                $line = false; // the server has no key of this type
            } finally {
                $ssh->disconnect();
            }

            $key = is_string($line) ? HostKey::fromOpenSsh($line) : null;

            if ($key instanceof HostKey && $key->type === $type) {
                $keys[$key->fingerprint()] = $key;
            }
        }

        return array_values($keys);
    }

    public function refs(SshUrl $url, array $pinned, #[SensitiveParameter] string $secretKey, bool $privateNetwork): array
    {
        if ($pinned === []) {
            throw new GitConnectionUnusable('The server’s host key isn’t confirmed yet.');
        }

        $ssh = $this->connect($url, $privateNetwork, $this->algorithmsFor($pinned));

        try {
            $presented = $ssh->getServerPublicHostKey();
            $presentedKey = is_string($presented) ? HostKey::fromOpenSsh($presented) : null;
            $matches = $presentedKey instanceof HostKey && array_filter($pinned, static fn (HostKey $key): bool => $key->equals($presentedKey)) !== [];

            if (! $matches) {
                throw new GitConnectionUnusable("The host key of {$url->host} changed. Check it again in Settings → Git before launchroom connects.");
            }

            $key = EC::loadFormat('libsodium', $secretKey);

            if (! $key instanceof PrivateKey || ! $ssh->login($url->user, $key)) {
                throw new GitConnectionUnusable("{$url->host} refused the deploy key. Add it as a deploy key of the repository.");
            }

            $advertisement = '';
            // The remote's git service, not a shell on our side; the path's
            // grammar (SshUrl) has no quotes, so it can't leave them.
            $ssh->exec("git-upload-pack '{$url->path}'", static function (string $chunk) use (&$advertisement): bool {
                $advertisement .= $chunk;

                // Stop at the flush packet that ends the advertisement (or at the cap).
                return RefAdvertisement::isComplete($advertisement) || strlen($advertisement) > self::MAX_ADVERTISEMENT_BYTES;
            });
        } catch (GitConnectionUnusable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new GitConnectionUnusable("Reading branches from {$url->host} failed: ".mb_substr($e->getMessage(), 0, 160), $e->getCode(), $e);
        } finally {
            $ssh->disconnect();
        }

        return RefAdvertisement::parse($advertisement);
    }

    /**
     * @param  list<HostKey>  $pinned
     * @return list<string>
     */
    private function algorithmsFor(array $pinned): array
    {
        $algorithms = [];

        foreach ($pinned as $key) {
            $algorithms = [...$algorithms, ...($key->type === 'ssh-rsa' ? ['rsa-sha2-512', 'rsa-sha2-256'] : [$key->type])];
        }

        return array_values(array_unique($algorithms));
    }

    /**
     * @param  list<string>  $hostKeyAlgorithms
     *
     * @throws GitConnectionUnusable
     */
    private function connect(SshUrl $url, bool $privateNetwork, array $hostKeyAlgorithms): SSH2
    {
        try {
            $guard = $privateNetwork ? $this->guard->withPrivateNetwork() : $this->guard;
            $target = $guard->check("https://{$url->host}:{$url->port}/");
        } catch (UnsafeUrl $e) {
            throw new GitConnectionUnusable("launchroom can’t connect to {$url->host}: {$e->getMessage()}.".($privateNetwork ? '' : ' An instance owner can allow servers on a private network.'), $e->getCode(), $e);
        }

        $ip = str_contains($target->ip, ':') ? "[{$target->ip}]" : $target->ip;
        $socket = @stream_socket_client("tcp://{$ip}:{$url->port}", $errno, $error, self::TIMEOUT_SECONDS);

        if ($socket === false) {
            throw new GitConnectionUnusable("launchroom couldn’t reach {$url->host}:{$url->port}.");
        }

        stream_set_timeout($socket, self::TIMEOUT_SECONDS);
        $ssh = new SSH2($socket, $url->port, self::TIMEOUT_SECONDS);
        $ssh->setTimeout(self::TIMEOUT_SECONDS);
        $ssh->setPreferredAlgorithms(['hostkey' => $hostKeyAlgorithms]);

        return $ssh;
    }
}
