<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Server;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * An in-memory Headscale v0.29 REST API behind Http::fake (docs/05 §2).
 * Keys are checked like the real one: only `apiKeys` with a future
 * expiration authenticate.
 */
final class FakeHeadscale
{
    public const string URL = 'http://headscale.test';

    public const string BOOTSTRAP_KEY = 'hskey-api-BOOTSTRAP000-bootstrapsecretbootstrapsecret';

    /** @var array<string, array{prefix: string, expired: bool}> key => meta */
    public array $apiKeys = [];

    /** @var array<string, string> id => name */
    public array $users = [];

    /** @var array<string, array{id: string, key: string, tags: list<string>, expired: bool, expiration: string}> */
    public array $preAuthKeys = [];

    /** @var array<string, array{id: string, name: string, ipAddresses: list<string>, tags: list<string>, online: bool, preAuthKey?: array{id: string}}> */
    public array $nodes = [];

    public ?string $policy = null;

    public bool $down = false;

    private int $sequence = 0;

    public static function install(): self
    {
        $fake = new self;
        $fake->apiKeys[self::BOOTSTRAP_KEY] = ['prefix' => 'BOOTSTRAP000', 'expired' => false];

        config([
            'launchroom.mesh.headscale_url' => self::URL,
            'launchroom.mesh.login_server' => 'https://dash.example.com',
        ]);

        Http::fake([self::URL.'/*' => $fake->handle(...)]);

        return $fake;
    }

    /**
     * Writes the installer's bootstrap key file and points the config at it.
     */
    public function writeBootstrapFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hs-bootstrap-');

        if ($path === false) {
            throw new RuntimeException('tempnam failed');
        }

        file_put_contents($path, self::BOOTSTRAP_KEY."\n");
        config(['launchroom.mesh.bootstrap_key_path' => $path]);

        return $path;
    }

    /**
     * A node as registered with $preAuthKeyId (null: a key unknown to us).
     */
    public function addNode(string $ip, array $tags = [], ?string $preAuthKeyId = null): string
    {
        $id = (string) ++$this->sequence;
        $this->nodes[$id] = ['id' => $id, 'name' => 'node-'.$id, 'ipAddresses' => [$ip], 'tags' => $tags, 'online' => true, ...($preAuthKeyId === null ? [] : ['preAuthKey' => ['id' => $preAuthKeyId]])];

        return $id;
    }

    /**
     * $server joins with the latest pre-auth key issued to it.
     */
    public function join(Server $server, string $ip, array $tags = []): string
    {
        $keys = $server->refresh()->mesh_auth_key_ids;

        return $this->addNode($ip, $tags, $keys === [] ? null : $keys[array_key_last($keys)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Request $request): array
    {
        $data = json_decode($request->body(), true);

        return is_array($data) ? $data : [];
    }

    private function handle(Request $request): mixed
    {
        if ($this->down) {
            return Http::response(['code' => 14, 'message' => 'unavailable'], 503);
        }

        $token = substr($request->header('Authorization')[0] ?? '', 7);

        if (! isset($this->apiKeys[$token]) || $this->apiKeys[$token]['expired']) {
            return Http::response(['code' => 16, 'message' => 'invalid key '.$token], 401);
        }

        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $method = $request->method();
        $body = $this->json($request);

        return match (true) {
            $method === 'GET' && $path === '/api/v1/user' => Http::response(['users' => array_values(array_map(
                static fn (string $name, string $id): array => ['id' => $id, 'name' => $name],
                $this->users,
                array_keys($this->users),
            ))]),
            $method === 'POST' && $path === '/api/v1/user' => $this->createUser((string) ($body['name'] ?? '')),
            $method === 'POST' && $path === '/api/v1/preauthkey' => $this->createPreAuthKey($body),
            $method === 'POST' && $path === '/api/v1/preauthkey/expire' => $this->expirePreAuthKey((string) ($body['id'] ?? '')),
            $method === 'GET' && $path === '/api/v1/node' => Http::response(['nodes' => array_values($this->nodes)]),
            $method === 'DELETE' && str_starts_with($path, '/api/v1/node/') => $this->deleteNode(substr($path, 13)),
            $method === 'POST' && preg_match('#^/api/v1/node/(\d+)/tags$#', $path, $m) === 1 => $this->setTags($m[1], $body),
            $method === 'PUT' && $path === '/api/v1/policy' => $this->setPolicy((string) ($body['policy'] ?? '')),
            $method === 'GET' && $path === '/api/v1/policy' => $this->policy === null ? Http::response(['code' => 5, 'message' => 'not found'], 404) : Http::response(['policy' => $this->policy]),
            $method === 'POST' && $path === '/api/v1/apikey' => $this->createApiKey(),
            $method === 'POST' && $path === '/api/v1/apikey/expire' => $this->expireApiKey((string) ($body['prefix'] ?? '')),
            default => Http::response(['code' => 5, 'message' => 'not found'], 404),
        };
    }

    private function createUser(string $name): mixed
    {
        $id = (string) ++$this->sequence;
        $this->users[$id] = $name;

        return Http::response(['user' => ['id' => $id, 'name' => $name]]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function createPreAuthKey(array $body): mixed
    {
        if (($body['reusable'] ?? null) !== false || ($body['ephemeral'] ?? null) !== false || ! isset($this->users[(string) ($body['user'] ?? '')])) {
            return Http::response(['code' => 3, 'message' => 'bad request'], 400);
        }

        $id = (string) ++$this->sequence;
        $key = 'hskey-auth-'.bin2hex(random_bytes(8)).'-'.bin2hex(random_bytes(16));
        $tags = is_array($body['aclTags'] ?? null) ? array_values(array_map(strval(...), $body['aclTags'])) : [];
        $this->preAuthKeys[$id] = ['id' => $id, 'key' => $key, 'tags' => $tags, 'expired' => false, 'expiration' => (string) ($body['expiration'] ?? '')];

        return Http::response(['preAuthKey' => ['id' => $id, 'key' => $key, 'aclTags' => $tags, 'expiration' => $body['expiration'] ?? null]]);
    }

    private function expirePreAuthKey(string $id): mixed
    {
        if (! isset($this->preAuthKeys[$id])) {
            return Http::response(['code' => 5, 'message' => 'not found'], 404);
        }

        $this->preAuthKeys[$id]['expired'] = true;

        return Http::response([]);
    }

    private function deleteNode(string $id): mixed
    {
        if (! isset($this->nodes[$id])) {
            return Http::response(['code' => 5, 'message' => 'not found'], 404);
        }

        unset($this->nodes[$id]);

        return Http::response([]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function setTags(string $id, array $body): mixed
    {
        if (! isset($this->nodes[$id])) {
            return Http::response(['code' => 5, 'message' => 'not found'], 404);
        }

        $this->nodes[$id]['tags'] = is_array($body['tags'] ?? null) ? array_values(array_map(strval(...), $body['tags'])) : [];

        return Http::response(['node' => $this->nodes[$id]]);
    }

    private function setPolicy(string $policy): mixed
    {
        if (json_decode($policy) === null) {
            return Http::response(['code' => 3, 'message' => 'invalid policy'], 400);
        }

        $this->policy = $policy;

        return Http::response(['policy' => $policy]);
    }

    private function createApiKey(): mixed
    {
        $prefix = strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
        $key = 'hskey-api-'.$prefix.'-'.bin2hex(random_bytes(16));
        $this->apiKeys[$key] = ['prefix' => $prefix, 'expired' => false];

        return Http::response(['apiKey' => $key]);
    }

    private function expireApiKey(string $prefix): mixed
    {
        foreach ($this->apiKeys as $key => $meta) {
            if ($meta['prefix'] === $prefix) {
                $this->apiKeys[$key]['expired'] = true;

                return Http::response([]);
            }
        }

        return Http::response(['code' => 5, 'message' => 'not found'], 404);
    }

    /**
     * The API keys that still authenticate.
     *
     * @return list<string>
     */
    public function liveApiKeys(): array
    {
        return array_keys(array_filter($this->apiKeys, static fn (array $meta): bool => ! $meta['expired']));
    }
}
