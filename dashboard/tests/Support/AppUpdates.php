<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Connections\CreateConnection;
use App\Actions\Deployments\DeployEnvironment;
use App\Contracts\DnsResolver;
use App\Data\Audit\AuditActor;
use App\Enums\ConnectionMode;
use App\Enums\ConnectionStyle;
use App\Models\Server;
use App\Models\ServiceInstance;
use App\Models\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * App update test helpers (D106): a live Listmonk image service (optionally
 * connected to a live Postgres) and a fake Docker Hub that answers tag
 * lists, manifest digests and publish dates.
 */
final class AppUpdates
{
    /** @var array{tags: list<string>, digests: array<string, string>, status: int} */
    private static array $hub = ['tags' => [], 'digests' => [], 'status' => 200];

    public const string RUNNING = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * @return array{Team, Server, ServiceInstance, ServiceInstance|null}
     */
    public static function listmonk(?Team $team = null, string $tag = 'v6.2.0', bool $withDatabase = false): array
    {
        $team ??= Team::factory()->create();
        [$server] = Projects::server($team);
        $project = Projects::create($team, 'news-'.bin2hex(random_bytes(3)));
        $listmonk = Projects::image($project, 'listmonk', 'docker.io/listmonk/listmonk:'.$tag.'@'.self::RUNNING, 9000);
        $postgres = null;

        if ($withDatabase) {
            $database = Projects::postgres($project);
            resolve(CreateConnection::class)->handle($listmonk, $database, ConnectionMode::Private, ConnectionStyle::Url, null, AuditActor::system());
            $postgres = ServiceInstance::query()->where('service_id', $database->id)->sole();
        }

        self::deploy(ServiceInstance::query()->where('service_id', $listmonk->id)->sole(), $server);

        return [$team, $server, ServiceInstance::query()->where('service_id', $listmonk->id)->sole(), $postgres?->refresh()];
    }

    public static function deploy(ServiceInstance $instance, Server $server): void
    {
        resolve(DeployEnvironment::class)->handle($instance->environment, null, AuditActor::system());
        self::settle($server);
    }

    public static function settle(Server $server): void
    {
        for ($i = 0; $i < 4; $i++) {
            Projects::report($server);
        }
    }

    /**
     * A public DNS answer for every host: registries are faked over HTTP.
     */
    public static function fakeDns(): void
    {
        app()->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    /**
     * Docker Hub with these tags; `$digests` maps a tag to the digest it
     * names (default: the running image for v6.2.0 and latest, else one
     * derived from the tag).
     *
     * @param  list<string>  $tags
     * @param  array<string, string>  $digests
     */
    public static function fakeHub(array $tags, array $digests = [], int $status = 200): void
    {
        // The running tag names the running image unless a test says otherwise.
        self::$hub = ['tags' => $tags, 'digests' => $digests + ['v6.2.0' => self::RUNNING, 'latest' => self::RUNNING], 'status' => $status];

        // Registered again per call; every copy reads the latest answers.
        Http::fake(static function (Request $request) {
            $url = $request->url();

            if (self::$hub['status'] !== 200) {
                return Http::response('', self::$hub['status']);
            }

            if (str_contains($url, '/tags/list')) {
                return Http::response(['name' => 'listmonk/listmonk', 'tags' => self::$hub['tags']]);
            }

            if (preg_match('#/manifests/([^/?]+)$#', $url, $m) === 1 && $request->method() === 'HEAD') {
                $tag = rawurldecode($m[1]);

                return Http::response('', 200, ['Docker-Content-Digest' => self::$hub['digests'][$tag] ?? self::digestOf($tag)]);
            }

            if (str_starts_with($url, 'https://hub.docker.com/')) {
                return Http::response(['tag_last_pushed' => str_contains($url, 'v6.2.0') ? '2023-03-01T10:00:00Z' : '2026-11-10T10:00:00Z']);
            }

            return Http::response('', 404);
        });
    }

    public static function digestOf(string $tag): string
    {
        return 'sha256:'.hash('sha256', $tag);
    }
}
