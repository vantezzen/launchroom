<?php

declare(strict_types=1);

use App\Contracts\DnsResolver;
use App\Data\Domains\CertificateData;
use App\Data\Protocol\V1\IngressStatus;
use App\Data\Protocol\V1\Report;
use App\Enums\AuditAction;
use App\Enums\DnsProvider;
use App\Enums\TeamRole;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Services\Ingress\DnsProviderVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Projects;
use Tests\Support\ProtocolSchemas;

beforeEach(function (): void {
    Projects::useTestKek();
    app()->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function resolve(string $hostname): array
        {
            return ['104.16.132.229'];
        }
    });
    config(['inertia.ssr.enabled' => false, 'launchroom.ingress.caddy_modules' => true, 'launchroom.ingress.dns_providers' => []]);
    Http::preventStrayRequests();
    InstanceSettings::current()->forceFill(['apps_domain' => 'apps.example.com'])->save();
    $this->team = Team::factory()->create();
    $this->owner = memberOf($this->team, TeamRole::Owner);
    $this->owner->forceFill(['is_instance_owner' => true])->save();
    $this->admin = memberOf($this->team, TeamRole::Admin);
});

function cloudflareOk(): void
{
    Http::fake([
        'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['success' => true, 'result' => ['status' => 'active']]),
        'api.cloudflare.com/client/v4/zones?name=apps.example.com' => Http::response(['success' => true, 'result' => []]),
        'api.cloudflare.com/client/v4/zones?name=example.com' => Http::response(['success' => true, 'result' => [['id' => str_repeat('a', 32), 'name' => 'example.com']]]),
        'api.cloudflare.com/client/v4/zones/'.str_repeat('a', 32).'/dns_records*' => Http::response(['success' => true, 'result' => []]),
    ]);
}

test('only instance owners see and change ingress settings', function (): void {
    $this->actingAs($this->admin)->get(route('servers.ingress.show'))->assertForbidden();
    $this->actingAs($this->admin)->withSession(passwordConfirmed())->put(route('servers.ingress.dns.update'), ['provider' => 'cloudflare', 'token' => 'x'])->assertForbidden();
    $this->actingAs($this->admin)->post(route('servers.ingress.dns.verify'))->assertForbidden();
    $this->actingAs($this->admin)->withSession(passwordConfirmed())->delete(route('servers.ingress.dns.destroy'))->assertForbidden();

    $this->actingAs($this->admin)->get(route('network-settings.edit'))->assertForbidden();
    $this->actingAs($this->owner)->get(route('servers.ingress.show'))
        ->assertRedirect(route('network-settings.edit').'#public-traffic');

    $this->actingAs($this->owner)->get(route('network-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/network')
            ->where('ingress.appsDomain', 'apps.example.com')
            ->where('ingress.dnsProvider', null)
            ->where('ingress.hasToken', false));
});

test('setting the provider needs a recent password, stores the token encrypted and checks it', function (): void {
    cloudflareOk();

    $this->actingAs($this->owner)->put(route('servers.ingress.dns.update'), ['provider' => 'cloudflare', 'token' => 'cf-SECRET'])->assertRedirect();
    expect(InstanceSettings::current()->dns_provider)->toBeNull();

    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->put(route('servers.ingress.dns.update'), ['provider' => 'cloudflare', 'token' => 'cf-SECRET'])
        ->assertRedirect()
        ->assertSessionMissing('_old_input');

    $settings = InstanceSettings::current();
    expect($settings->dns_provider)->toBe(DnsProvider::Cloudflare)
        ->and($settings->dns_verification['ok'])->toBeTrue()
        ->and($settings->dns_verification['zone'])->toBe('example.com')
        ->and($settings->dns_verified_at)->not->toBeNull()
        ->and(json_encode($settings->toArray()))->not->toContain('cf-SECRET')
        ->and(AuditEvent::query()->where('action', AuditAction::DnsProviderChanged->value)->sole()->meta)->not->toHaveKey('token');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer cf-SECRET') && str_starts_with($request->url(), DnsProviderVerifier::CLOUDFLARE_API));

    $this->actingAs($this->owner)->get(route('network-settings.edit'))
        ->assertInertia(fn ($page) => $page->where('ingress.hasToken', true)->where('ingress.verification.ok', true));

    // Switching the provider needs new credentials.
    $this->actingAs($this->owner)->withSession(passwordConfirmed())->from(route('network-settings.edit'))
        ->put(route('servers.ingress.dns.update'), ['provider' => 'hetzner'])
        ->assertSessionHasErrors('credentials.token');

    $this->actingAs($this->owner)->withSession(passwordConfirmed())->delete(route('servers.ingress.dns.destroy'))->assertRedirect();
    expect(InstanceSettings::current()->dns_provider)->toBeNull();
});

test('the verifier explains rejected and unscoped tokens', function (): void {
    Http::fake(['api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['success' => false], 401)]);
    $result = resolve(DnsProviderVerifier::class)->verify(DnsProvider::Cloudflare, ['token' => 'bad'], 'apps.example.com');
    expect($result->ok)->toBeFalse()->and($result->message)->toContain('rejected');

    Http::fake([
        'api.hetzner.cloud/v1/zones?name=apps.example.com' => Http::response(['zones' => []]),
        'api.hetzner.cloud/v1/zones?name=example.com' => Http::response(['zones' => [['name' => 'example.com']]]),
    ]);
    $result = resolve(DnsProviderVerifier::class)->verify(DnsProvider::Hetzner, ['token' => 'hz'], 'apps.example.com');
    expect($result->ok)->toBeTrue()->and($result->zone)->toBe('example.com');

    expect(resolve(DnsProviderVerifier::class)->verify(DnsProvider::Hetzner, ['token' => 'hz'], '')->ok)->toBeFalse();
});

test('expiry warnings follow the certificate lifetime', function (): void {
    $this->travelTo(today());

    expect(CertificateData::warning(now()->subDays(60), now()->addDays(30)))->toBeNull()
        ->and(CertificateData::warning(now()->subDays(80), now()->addDays(10)))->toBe('expiring')
        // 45-day certificates: 7.5 days is overdue, 10 is not.
        ->and(CertificateData::warning(now()->subDays(35), now()->addDays(10)))->toBeNull()
        ->and(CertificateData::warning(now()->subDays(38), now()->addDays(7)))->toBe('expiring')
        ->and(CertificateData::warning(now()->subDays(90), now()->subMinute()))->toBe('expired')
        ->and(CertificateData::warning(null, null))->toBeNull();
});

test('the certificate report fixture decodes into the PHP Data objects', function (): void {
    $json = (string) file_get_contents(ProtocolSchemas::root().'/fixtures/v1/report.certificates.json');
    $report = Report::validateAndCreate(json_decode($json, true, 64, JSON_THROW_ON_ERROR));

    expect($report->ingress)->toBeInstanceOf(IngressStatus::class)
        ->and($report->ingress->certificates)->toHaveCount(3)
        ->and($report->ingress->certificates[1]->wildcard)->toBeTrue();
});

test('the form saves a multi-field provider and only shows its non-secret values back', function (): void {
    Http::fake([
        'eu.api.ovh.com/1.0/auth/time' => Http::response('1760000000'),
        'eu.api.ovh.com/1.0/domain/zone/apps.example.com' => Http::response(['message' => 'not found'], 404),
        'eu.api.ovh.com/1.0/domain/zone/example.com' => Http::response(['name' => 'example.com']),
    ]);

    $this->actingAs($this->owner)->withSession(passwordConfirmed())
        ->put(route('servers.ingress.dns.update'), ['provider' => 'ovh', 'credentials' => [
            'endpoint' => 'ovh-eu', 'applicationKey' => 'app-key', 'applicationSecret' => 'app-SECRET', 'consumerKey' => 'consumer-SECRET',
        ]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(InstanceSettings::current()->dns_verification['ok'])->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://eu.api.ovh.com/1.0/domain/zone/example.com'
        && $request->header('X-Ovh-Signature')[0] === '$1$'.sha1('app-SECRET+consumer-SECRET+GET+https://eu.api.ovh.com/1.0/domain/zone/example.com++1760000000'));

    $this->actingAs($this->owner)->get(route('network-settings.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('ingress.dnsProvider', 'ovh')
            ->where('ingress.storedFields', ['endpoint' => 'ovh-eu', 'applicationKey' => 'app-key', 'applicationSecret' => null, 'consumerKey' => null])
            ->where('ingress.providers.7.fields.0.options', DnsProvider::OVH_ENDPOINTS));

    // Unknown fields and other grammars are refused.
    $this->actingAs($this->owner)->withSession(passwordConfirmed())->from(route('network-settings.edit'))
        ->put(route('servers.ingress.dns.update'), ['provider' => 'ovh', 'credentials' => ['endpoint' => 'https://evil.example']])
        ->assertSessionHasErrors('credentials.endpoint');
    $this->actingAs($this->owner)->withSession(passwordConfirmed())->from(route('network-settings.edit'))
        ->put(route('servers.ingress.dns.update'), ['provider' => 'ovh', 'credentials' => ['url' => 'x']])
        ->assertSessionHasErrors('credentials');
});

test('route 53 is checked with a SigV4-signed read', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0, 0));
    Http::fake([
        'route53.amazonaws.com/2013-04-01/hostedzonesbyname?dnsname=apps.example.com*' => Http::response(
            '<ListHostedZonesByNameResponse><HostedZones><HostedZone><Id>/hostedzone/Z111</Id><Name>example.com.</Name></HostedZone></HostedZones></ListHostedZonesByNameResponse>',
        ),
        'route53.amazonaws.com/2013-04-01/hostedzonesbyname?dnsname=example.com*' => Http::response(
            '<ListHostedZonesByNameResponse><HostedZones><HostedZone><Id>/hostedzone/Z2ABC</Id><Name>example.com.</Name></HostedZone></HostedZones></ListHostedZonesByNameResponse>',
        ),
        'route53.amazonaws.com/2013-04-01/hostedzone/Z2ABC/rrset*' => Http::response('<ListResourceRecordSetsResponse/>'),
    ]);

    $result = resolve(DnsProviderVerifier::class)->verify(DnsProvider::Route53, ['accessKeyId' => 'AKIDEXAMPLE', 'secretAccessKey' => 'wJalr-SECRET'], 'apps.example.com');

    expect($result->ok)->toBeTrue()->and($result->zone)->toBe('example.com');
    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'dnsname=example.com')) {
            return false;
        }
        $date = '20261020T120000Z';
        $canonical = "GET\n/2013-04-01/hostedzonesbyname\ndnsname=example.com&maxitems=1\nhost:route53.amazonaws.com\nx-amz-date:{$date}\n\nhost;x-amz-date\n".hash('sha256', '');
        $toSign = "AWS4-HMAC-SHA256\n{$date}\n20261020/us-east-1/route53/aws4_request\n".hash('sha256', $canonical);
        $key = 'AWS4wJalr-SECRET';
        foreach (['20261020', 'us-east-1', 'route53', 'aws4_request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }

        return $request->header('X-Amz-Date')[0] === $date
            && $request->header('Authorization')[0] === 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20261020/us-east-1/route53/aws4_request, SignedHeaders=host;x-amz-date, Signature='.hash_hmac('sha256', $toSign, $key);
    });
});

test('route 53 explains a rejected key and a key without permissions', function (): void {
    Http::fake(['route53.amazonaws.com/*' => Http::sequence()
        ->push('<ErrorResponse><Error><Code>InvalidClientTokenId</Code></Error></ErrorResponse>', 403)
        ->push('<ErrorResponse><Error><Code>AccessDenied</Code></Error></ErrorResponse>', 403)]);

    expect(resolve(DnsProviderVerifier::class)->verify(DnsProvider::Route53, ['accessKeyId' => 'AKIDEXAMPLE', 'secretAccessKey' => 'x'], 'apps.example.com')->message)
        ->toContain('rejected the access key')
        ->and(resolve(DnsProviderVerifier::class)->verify(DnsProvider::Route53, ['accessKeyId' => 'AKIDEXAMPLE', 'secretAccessKey' => 'x'], 'apps.example.com')->message)
        ->toContain('may not list hosted zones');
});

test('the token-based providers find the zone or explain why not', function (DnsProvider $provider, array $credentials, Closure $fakes, bool $ok, string $message, string $auth): void {
    Http::fake($fakes());

    $result = resolve(DnsProviderVerifier::class)->verify($provider, $credentials, 'apps.example.com');

    expect($result->ok)->toBe($ok)->and($result->message)->toContain($message);
    if ($ok) {
        expect($result->zone)->toBe('example.com');
    }
    if ($auth !== '') {
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', $auth));
    }
    // Only the provider's fixed API host is ever called.
    Http::assertNotSent(fn (Request $request): bool => ! in_array(parse_url($request->url(), PHP_URL_HOST), [
        'api.digitalocean.com', 'api.porkbun.com', 'desec.io', 'api.gandi.net', 'ccp.netcup.net',
    ], true));
})->with([
    'digitalocean' => [DnsProvider::DigitalOcean, ['token' => 'do-SECRET'], fn (): array => [
        'api.digitalocean.com/v2/domains/apps.example.com' => Http::response(['id' => 'not_found'], 404),
        'api.digitalocean.com/v2/domains/example.com' => Http::response(['domain' => ['name' => 'example.com']]),
    ], true, 'Works for the zone example.com', 'Bearer do-SECRET'],
    'digitalocean rejected' => [DnsProvider::DigitalOcean, ['token' => 'bad'], fn (): array => [
        'api.digitalocean.com/*' => Http::response(['id' => 'unauthorized'], 401),
    ], false, 'rejected', ''],
    'porkbun' => [DnsProvider::Porkbun, ['apiKey' => 'pk1', 'secretKey' => 'sk1'], fn (): array => [
        'api.porkbun.com/api/json/v3/ping' => Http::response(['status' => 'SUCCESS']),
        'api.porkbun.com/api/json/v3/dns/retrieve/apps.example.com' => Http::response(['status' => 'ERROR', 'message' => 'Invalid domain.'], 400),
        'api.porkbun.com/api/json/v3/dns/retrieve/example.com' => Http::response(['status' => 'SUCCESS', 'records' => []]),
    ], true, 'Works', ''],
    'porkbun without API access' => [DnsProvider::Porkbun, ['apiKey' => 'pk1', 'secretKey' => 'sk1'], fn (): array => [
        'api.porkbun.com/api/json/v3/ping' => Http::response(['status' => 'SUCCESS']),
        'api.porkbun.com/api/json/v3/dns/retrieve/apps.example.com' => Http::response(['status' => 'ERROR', 'message' => 'Invalid domain.'], 400),
        'api.porkbun.com/api/json/v3/dns/retrieve/example.com' => Http::response(['status' => 'ERROR', 'message' => 'Domain is not opted in to API access.'], 400),
    ], false, 'Turn on API access for example.com', ''],
    'desec' => [DnsProvider::Desec, ['token' => 'desec-SECRET'], fn (): array => [
        'desec.io/api/v1/domains/?owns_qname=apps.example.com' => Http::response([['name' => 'example.com']]),
    ], true, 'Works', 'Token desec-SECRET'],
    'gandi' => [DnsProvider::Gandi, ['token' => 'gandi-SECRET'], fn (): array => [
        'api.gandi.net/v5/livedns/domains/apps.example.com' => Http::response(['code' => 404], 404),
        'api.gandi.net/v5/livedns/domains/example.com' => Http::response(['fqdn' => 'example.com']),
    ], true, 'Works', 'Bearer gandi-SECRET'],
    'gandi forbidden' => [DnsProvider::Gandi, ['token' => 'gandi-SECRET'], fn (): array => [
        'api.gandi.net/*' => Http::response(['code' => 403], 403),
    ], false, 'may not manage', ''],
    'netcup' => [DnsProvider::Netcup, ['customerNumber' => '12345', 'apiKey' => 'nk', 'apiPassword' => 'np'], fn (): array => [
        'ccp.netcup.net/*' => Http::sequence()
            ->push(['status' => 'success', 'responsedata' => ['apisessionid' => 'sess']])
            ->push(['status' => 'error', 'statuscode' => 5029])
            ->push(['status' => 'success', 'responsedata' => ['name' => 'example.com']])
            ->push(['status' => 'success']),
    ], true, 'Works', ''],
    'netcup rejected' => [DnsProvider::Netcup, ['customerNumber' => '12345', 'apiKey' => 'nk', 'apiPassword' => 'bad'], fn (): array => [
        'ccp.netcup.net/*' => Http::response(['status' => 'error', 'statuscode' => 4013]),
    ], false, 'netcup rejected', ''],
]);
