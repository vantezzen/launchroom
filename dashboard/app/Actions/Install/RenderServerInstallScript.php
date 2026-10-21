<?php

declare(strict_types=1);

namespace App\Actions\Install;

use App\Services\Crypto\Signer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * The daemon installer served at /install/server.sh (docs/11 §3, docs/03 §3):
 * deploy/install/server.sh with this instance's URL, its public signing key,
 * the daemon image and this release's version (the signed bundle server.sh
 * verifies, docs/11 §3) filled in, and deploy/daemon/compose.yml embedded.
 *
 * Contains no secrets: the join token is never part of it (the user passes
 * LR_JOIN_TOKEN through the environment). Every inserted value is checked
 * against a strict grammar because it ends up in a shell script; anything
 * unexpected yields null (the controller answers 503) instead of a script.
 */
final readonly class RenderServerInstallScript
{
    private const string URL_PATTERN = '#^https?://(?:[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*|\[[0-9A-Fa-f:]+\])(?::[0-9]{1,5})?$#D';

    private const string KEY_PATTERN = '#^ed25519:[A-Za-z0-9+/]{43}=$#D';

    private const string IMAGE_PATTERN = '#^[a-z0-9][a-z0-9._/:@-]{0,254}$#D';

    private const string VERSION_PATTERN = '#^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$#D';

    private const string COMPOSE_MARKER = '#__LAUNCHROOM_DAEMON_COMPOSE__';

    private const string HEREDOC_END = 'LAUNCHROOM_DAEMON_COMPOSE_EOF';

    public function __construct(
        private Container $container,
        private Filesystem $files,
    ) {}

    public function handle(): ?string
    {
        $url = rtrim(config()->string('app.url'), '/');
        $image = config('launchroom.daemon.image');
        $version = config('launchroom.version');
        $directory = config()->string('launchroom.install.scripts_path');
        $scriptPath = $directory.'/server.sh';
        $composePath = $directory.'/daemon-compose.yml';

        if (! is_string($image) || preg_match(self::IMAGE_PATTERN, $image) !== 1
            || ! is_string($version) || strlen($version) > 64 || preg_match(self::VERSION_PATTERN, $version) !== 1
            || preg_match(self::URL_PATTERN, $url) !== 1
            || ! $this->files->isFile($scriptPath) || ! $this->files->isFile($composePath)) {
            return null;
        }

        try {
            $key = $this->container->make(Signer::class)->publicKey();
        } catch (Throwable) {
            return null; // No signing key yet (launchroom:keys:generate).
        }

        $script = $this->files->get($scriptPath);
        $compose = rtrim($this->files->get($composePath), "\n");

        if (preg_match(self::KEY_PATTERN, $key) !== 1
            || preg_match('/^'.self::HEREDOC_END.'$/m', $compose) === 1
            || substr_count($script, "\n".self::COMPOSE_MARKER."\n") !== 1) {
            return null;
        }

        return strtr($script, [
            '__LAUNCHROOM_URL__' => $url,
            '__LAUNCHROOM_CP_KEY__' => $key,
            '__LAUNCHROOM_DAEMON_IMAGE__' => $image,
            '__LAUNCHROOM_VERSION__' => $version,
            "\n".self::COMPOSE_MARKER."\n" => "\n".$compose."\n",
        ]);
    }
}
