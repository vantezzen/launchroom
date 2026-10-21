<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Servers\HostFactsData;
use App\Enums\ServerRole;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Team;
use App\Support\Crypto\PublicKeyFormat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => 'server-'.fake()->unique()->numberBetween(1, 99999),
            'roles' => [ServerRole::Edge, ServerRole::Runtime, ServerRole::Build],
            'daemon_version' => '0.1.0',
            'protocol_version' => 'v1',
            'signing_public_key' => PublicKeyFormat::formatEd25519(random_bytes(32)),
            'sealing_public_key' => 'x25519:'.base64_encode(random_bytes(32)),
            'facts' => new HostFactsData('host', 'linux', 'arm64', 4, 8192),
            'enrolled_at' => now(),
        ];
    }

    /**
     * An approved, online server. Status is set directly here: factories
     * create fixtures, they don't model transitions.
     */
    public function online(): static
    {
        return $this->afterCreating(function (Server $server): void {
            $server->forceFill(['status' => ServerStatus::Online, 'approved_at' => now(), 'last_seen_at' => now()])->save();
        });
    }

    /**
     * A server signing with the Ed25519 key derived from $seed.
     */
    public function withSigningSeed(string $seed): static
    {
        if ($seed === '') {
            throw new \InvalidArgumentException('Empty seed.');
        }

        return $this->state(fn (): array => [
            'signing_public_key' => PublicKeyFormat::formatEd25519(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed))),
        ]);
    }
}
