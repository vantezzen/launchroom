<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\DnsResolver;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\SecretKey;
use App\Services\Crypto\Signer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;
use Tests\Support\FakeDns;

abstract class TestCase extends BaseTestCase
{
    /**
     * Parallel workstreams may each use their own test database
     * (`make test-dashboard TEST_DB=launchroom_test_x`). phpunit.xml forces
     * DB_DATABASE=launchroom_test; only names with that prefix are accepted,
     * so tests can never point at dev or production data.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $database = getenv('LR_TEST_DATABASE');
        if (is_string($database) && $database !== '') {
            // Never fall back silently to the shared launchroom_test database:
            // parallel workstreams would migrate:fresh each other's tables.
            if (preg_match('/^launchroom_test(_[a-z0-9_]+)?$/', $database) !== 1) {
                throw new RuntimeException("LR_TEST_DATABASE must match launchroom_test[_suffix] (a-z, 0-9, _), got {$database}.");
            }

            $app->make('config')->set('database.connections.pgsql.database', $database);
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests assert on Inertia responses, not on built assets, so
        // they must not depend on `npm run build` having produced a manifest.
        $this->withoutVite();

        // A fixed control-plane signing key; tests never touch key files
        // unless they test them.
        $this->app->instance(Signer::class, Signer::fromSeed(self::SIGNING_KID, self::signingSeed()));

        // An in-memory KEK, so envelope encryption (project keys, sealed env)
        // works without a key file. Tests of the key file itself rebind it.
        $this->app->instance(Envelope::class, new Envelope(SecretKey::generate()));

        // Public DNS checks never leave the test (empty zone unless a test adds records).
        FakeDns::install();

        // Neither do SafeHttpClient's lookups (favicons fetched after a
        // deploy goes live, …): nothing resolves unless a test binds its own.
        $this->app->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function resolve(string $hostname): array
            {
                return [];
            }
        });
    }

    public const string SIGNING_KID = 'cp-test';

    public static function signingSeed(): string
    {
        return str_repeat("\x42", 32);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
