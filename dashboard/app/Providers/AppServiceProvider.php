<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\BuildOrchestrator;
use App\Contracts\DnsResolver;
use App\Contracts\Git\TrackedRefs;
use App\Contracts\ImageDigestResolver;
use App\Contracts\ReleaseRunner;
use App\Drivers\DatabaseEngines\DatabaseEngines;
use App\Http\Routing\TeamScopedBindings;
use App\Models\Connection;
use App\Models\DaemonTask;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Environment;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerEnrollment;
use App\Models\Service;
use App\Models\ServiceInstance;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\Variable;
use App\Models\Volume;
use App\Services\Audit\Transports\SyslogConnector;
use App\Services\Audit\Transports\TlsSyslogConnector;
use App\Services\Builds\TaskBuildOrchestrator;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\ProjectKeyring;
use App\Services\Crypto\Signer;
use App\Services\Crypto\SigningKeyFile;
use App\Services\Deployments\TaskReleaseRunner;
use App\Services\Git\ServiceTrackedRefs;
use App\Services\Http\SafeHttpClient;
use App\Services\Http\SystemDnsResolver;
use App\Services\Http\UrlGuard;
use App\Services\Images\RegistryImageDigestResolver;
use App\Services\Instance\DashboardOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);

        $this->app->bind(UrlGuard::class, fn (Application $app): UrlGuard => new UrlGuard(
            resolver: $app->make(DnsResolver::class),
            allowedSchemes: array_values(array_filter(config()->array('launchroom.safe_http.allowed_schemes'), is_string(...))),
        ));

        $this->app->bind(SafeHttpClient::class, fn (Application $app): SafeHttpClient => new SafeHttpClient(
            http: $app->make(HttpFactory::class),
            guard: $app->make(UrlGuard::class),
            connectTimeoutSeconds: config()->integer('launchroom.safe_http.connect_timeout'),
            timeoutSeconds: config()->integer('launchroom.safe_http.timeout'),
            maxResponseBytes: config()->integer('launchroom.safe_http.max_response_bytes'),
        ));

        // Resolved lazily: the app boots (and can run launchroom:keys:generate)
        // before a KEK exists; only code that needs secrets fails without it.
        $this->app->singleton(Envelope::class, fn (): Envelope => Envelope::fromKeyFile(
            config()->string('launchroom.kek_path'),
        ));

        // Same for the control-plane signing key (docs/03 §4.1).
        $this->app->singleton(Signer::class, fn (): Signer => SigningKeyFile::read(
            config()->string('launchroom.signing_key_path'),
        ));

        // Unwrapped project DEKs live for one request/job only.
        $this->app->scoped(ProjectKeyring::class);
        // The canonical dashboard origin, read once per request/job (docs/05 §4.1).
        $this->app->scoped(DashboardOrigin::class);
        $this->app->singleton(DatabaseEngines::class, static fn (): DatabaseEngines => DatabaseEngines::fromConfig());

        // Deployment extension points (docs/04 §11–13).
        $this->app->bind(BuildOrchestrator::class, TaskBuildOrchestrator::class);
        // Syslog over TLS for audit forwarding (docs/09 R34); tests swap the connector.
        $this->app->bind(SyslogConnector::class, TlsSyslogConnector::class);
        $this->app->bind(ReleaseRunner::class, TaskReleaseRunner::class);
        $this->app->bind(ImageDigestResolver::class, RegistryImageDigestResolver::class);
        // Refs the GitHub polling fallback watches (docs/06 §2).
        $this->app->bind(TrackedRefs::class, ServiceTrackedRefs::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();

        TeamScopedBindings::register();

        // Instance-level settings (platform domain, first-run checklist): instance owners.
        Gate::define('manageInstance', static fn (User $user): bool => $user->is_instance_owner === true);

        $this->useDashboardOrigin();

        // Generous per-IP budget for daemons: polling plus task traffic.
        RateLimiter::for('daemon', static fn (Request $request): Limit => Limit::perMinute(600)->by((string) $request->ip()));
    }

    /**
     * The canonical dashboard origin (docs/05 §4.1, D43) for queued jobs and
     * launchroom's own console commands (requests: UseDashboardOrigin).
     * Other commands (migrate, package discovery during image builds) keep
     * APP_URL and never need the database for it. New passkeys remember
     * the relying party they were registered for.
     */
    private function useDashboardOrigin(): void
    {
        Queue::before(static function (): void {
            resolve(DashboardOrigin::class)->apply(console: true);
        });

        Event::listen(static function (CommandStarting $event): void {
            if (str_starts_with($event->command, 'launchroom:')) {
                resolve(DashboardOrigin::class)->apply(console: true);
            }
        });

        Passkey::creating(static function (Passkey $passkey): void {
            if ($passkey->getAttribute('rp_id') === null) {
                $passkey->setAttribute('rp_id', Passkeys::relyingPartyId());
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    private function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Model::shouldBeStrict(! app()->isProduction());

        // docs/07-data-model.md: morph types are aliases, never class names.
        Relation::enforceMorphMap([
            'user' => User::class,
            'team' => Team::class,
            'membership' => Membership::class,
            'team_invitation' => TeamInvitation::class,
            'server' => Server::class,
            'server_enrollment' => ServerEnrollment::class,
            'daemon_task' => DaemonTask::class,
            'project' => Project::class,
            'environment' => Environment::class,
            'service' => Service::class,
            'service_instance' => ServiceInstance::class,
            'deployment' => Deployment::class,
            'variable' => Variable::class,
            'connection' => Connection::class,
            'domain' => Domain::class,
            'volume' => Volume::class,
        ]);

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
