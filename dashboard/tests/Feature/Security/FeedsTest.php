<?php

declare(strict_types=1);

use App\Actions\Security\EvaluateSecurityFindings;
use App\Actions\Security\SyncAdvisories;
use App\Actions\Security\SyncKnownExploited;
use App\Actions\Security\SyncLifecycles;
use App\Enums\Security\AdvisoryFeed;
use App\Enums\Security\SecurityFindingStatus;
use App\Enums\TeamRole;
use App\Models\Security\AdvisoryFeedState;
use App\Models\Security\SecurityFinding;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Projects;
use Tests\Support\Security;

/*
| Advisory feeds with recorded responses (docs/22 §4): OSV querybatch
| (batching, pages, 429 back-off, a refused query among good ones,
| withdrawn records, alias groups, severity, fixed versions), CISA KEV and
| endoflife.date. Nothing leaves the test: DNS and HTTP are faked.
*/

beforeEach(function (): void {
    Projects::useTestKek();
    Security::fakeDns();
    CarbonImmutable::setTestNow('2026-10-20 12:00:00');
    $this->team = Team::factory()->create();
    memberOf($this->team, TeamRole::Owner);
    Security::server($this->team);
    $this->project = Projects::create($this->team, 'Shop');
    [, $this->instance, $this->deployment] = Security::liveService($this->project, 'api', image: '127.0.0.1:5000/apps/x@'.Security::DIGEST);
    $this->inventory = Security::inventory($this->deployment, [
        Security::component('npm', 'lodash', '4.17.20'),
        Security::component('npm', 'minimist', '1.2.5', ['scope' => 'dev']),
        Security::component('deb', 'libssl3', '3.0.15-1~deb12u1', ['purl' => 'pkg:deb/debian/libssl3@3.0.15-1~deb12u1?distro=debian-12&upstream=openssl']),
        Security::component('binary', 'busybox', '1.36.1'),
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @return array<string, mixed>
 */
function osvRecord(string $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'modified' => '2026-10-01T00:00:00Z',
        'published' => '2026-09-01T00:00:00Z',
        'summary' => 'Prototype pollution in lodash',
        'details' => "lodash before 4.17.21 is vulnerable.\n\nSee https://example.com.",
        'severity' => [['type' => 'CVSS_V3', 'score' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H']],
        'database_specific' => ['severity' => 'CRITICAL', 'cwe_ids' => ['CWE-1321']],
        'affected' => [['package' => ['ecosystem' => 'npm', 'name' => 'lodash'], 'ranges' => [['type' => 'SEMVER', 'events' => [['introduced' => '0'], ['fixed' => '4.17.21']]]]]],
        'references' => [['type' => 'ADVISORY', 'url' => 'https://github.com/advisories/'.$id]],
        ...$overrides,
    ];
}

test('querybatch asks only ecosystem, name and version, follows pages and hydrates records once', function (): void {
    Http::fake(function (Request $request) {
        if ($request->url() === 'https://api.osv.dev/v1/querybatch') {
            $queries = $request->data()['queries'];
            $results = array_map(static function (array $q): array {
                if (($q['page_token'] ?? null) === 'p2') {
                    return ['vulns' => [['id' => 'PYSEC-2021-1', 'modified' => '2026-10-01T00:00:00Z']]];
                }

                return match ($q['package']['name']) {
                    'lodash' => ['vulns' => [['id' => 'GHSA-35jh-r3h4-6jhm', 'modified' => '2026-10-01T00:00:00Z']], 'next_page_token' => 'p2'],
                    'openssl' => ['vulns' => [['id' => 'DEBIAN-CVE-2024-5535', 'modified' => '2026-10-01T00:00:00Z']]],
                    default => [],
                };
            }, $queries);

            return Http::response(['results' => $results]);
        }

        return match (true) {
            str_ends_with($request->url(), 'GHSA-35jh-r3h4-6jhm') => Http::response(osvRecord('GHSA-35jh-r3h4-6jhm', ['aliases' => ['CVE-2021-23337']])),
            str_ends_with($request->url(), 'PYSEC-2021-1') => Http::response(osvRecord('PYSEC-2021-1', ['aliases' => ['GHSA-35jh-r3h4-6jhm'], 'database_specific' => []])),
            str_ends_with($request->url(), 'DEBIAN-CVE-2024-5535') => Http::response(osvRecord('DEBIAN-CVE-2024-5535', ['upstream' => ['CVE-2024-5535'], 'database_specific' => [], 'severity' => [], 'affected' => [['package' => ['ecosystem' => 'Debian:12', 'name' => 'openssl'], 'ranges' => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0']]]]]]])),
            default => Http::response([], 404),
        };
    });

    $result = resolve(SyncAdvisories::class)->handle();

    expect($result)->toBe(['queries' => 3, 'records' => 3, 'complete' => true, 'retryAfter' => null]);

    $batch = Http::recorded(static fn (Request $r): bool => str_ends_with($r->url(), 'querybatch'))->first()[0];
    expect($batch->data())->toBe(['queries' => [
        ['package' => ['ecosystem' => 'Debian:12', 'name' => 'openssl'], 'version' => '3.0.15-1~deb12u1'],
        ['package' => ['ecosystem' => 'npm', 'name' => 'lodash'], 'version' => '4.17.20'],
        ['package' => ['ecosystem' => 'npm', 'name' => 'minimist'], 'version' => '1.2.5'],
    ]]);

    // Aliases make one group; Debian's record groups under its CVE.
    expect(DB::table('advisories')->pluck('group_key', 'id')->all())->toEqual([
        'GHSA-35jh-r3h4-6jhm' => 'GHSA-35jh-r3h4-6jhm',
        'PYSEC-2021-1' => 'GHSA-35jh-r3h4-6jhm',
        'DEBIAN-CVE-2024-5535' => 'CVE-2024-5535',
    ]);

    $findings = SecurityFinding::query()->orderBy('name')->get();
    expect($findings)->toHaveCount(2)
        ->and($findings[0]->name)->toBe('libssl3')
        ->and($findings[0]->reasons)->toBe(['tls_at_edge', 'severity_unknown', 'no_fix'])
        ->and($findings[1]->name)->toBe('lodash')
        ->and($findings[1]->advisory_ids)->toBe(['GHSA-35jh-r3h4-6jhm', 'PYSEC-2021-1'])
        ->and($findings[1]->fixed_version)->toBe('4.17.21')
        ->and((float) $findings[1]->cvss_score)->toBe(9.8);

    // A second run within 6 hours asks nothing.
    Http::fake();
    expect(resolve(SyncAdvisories::class)->handle()['queries'])->toBe(0);
    Http::assertNothingSent();
});

test('a rate limit pauses the sync with a back-off and keeps the cursor', function (): void {
    Http::fake(['api.osv.dev/v1/querybatch' => Http::sequence()
        ->push('slow down', 429, ['Retry-After' => '120'])
        ->push('down', 503)]);

    $result = resolve(SyncAdvisories::class)->handle();
    $feed = AdvisoryFeedState::of(AdvisoryFeed::Osv);

    expect($result['complete'])->toBeFalse()
        ->and($result['retryAfter'])->toBe(120)
        ->and($feed->last_error)->toBe('The source answered HTTP 429.')
        ->and($feed->failing_since?->toIso8601ZuluString())->toBe('2026-10-20T12:00:00Z')
        ->and($feed->retry_at?->toIso8601ZuluString())->toBe('2026-10-20T12:02:00Z')
        ->and($feed->last_success_at)->toBeNull();

    // Without Retry-After the back-off doubles.
    resolve(SyncAdvisories::class)->handle();
    expect(AdvisoryFeedState::of(AdvisoryFeed::Osv)->backoff_seconds)->toBe(240);
});

test('a query osv.dev refuses is marked failed and the rest go through', function (): void {
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), 'querybatch')) {
            $names = array_map(static fn (array $q): string => $q['package']['name'], $request->data()['queries']);

            if (in_array('minimist', $names, true)) {
                return Http::response(['code' => 3, 'message' => 'Invalid query'], 400);
            }

            return Http::response(['results' => array_map(static fn (): array => [], $names)]);
        }

        return Http::response([], 404);
    });

    expect(resolve(SyncAdvisories::class)->handle()['complete'])->toBeTrue();

    $errors = DB::table('advisory_queries')->pluck('error', 'osv_name')->all();
    expect($errors['minimist'])->toBe('The source answered HTTP 400.')
        ->and($errors['lodash'])->toBeNull()
        ->and($errors['openssl'])->toBeNull();
});

test('withdrawn records match nothing and a modified record is fetched again', function (): void {
    Security::advisory('GHSA-35jh-r3h4-6jhm');
    Security::match('npm', 'lodash', '4.17.20', ['GHSA-35jh-r3h4-6jhm']);
    resolve(EvaluateSecurityFindings::class)->handle($this->team);
    expect(SecurityFinding::query()->sole()->status)->toBe(SecurityFindingStatus::Open);

    CarbonImmutable::setTestNow('2026-10-20 19:00:00');
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), 'querybatch')) {
            return Http::response(['results' => array_map(static fn (array $q): array => $q['package']['name'] === 'lodash'
                ? ['vulns' => [['id' => 'GHSA-35jh-r3h4-6jhm', 'modified' => '2026-10-15T00:00:00Z']]]
                : [], $request->data()['queries'])]);
        }

        return Http::response(osvRecord('GHSA-35jh-r3h4-6jhm', ['modified' => '2026-10-15T00:00:00Z', 'withdrawn' => '2026-10-15T00:00:00Z']));
    });

    resolve(SyncAdvisories::class)->handle();

    expect(DB::table('advisories')->value('withdrawn'))->not->toBeNull()
        ->and(SecurityFinding::query()->sole()->status)->toBe(SecurityFindingStatus::Resolved);
});

test('a source that is switched off is never asked', function (): void {
    AdvisoryFeedState::of(AdvisoryFeed::Osv)->forceFill(['enabled' => false])->save();
    AdvisoryFeedState::of(AdvisoryFeed::Kev)->forceFill(['enabled' => false])->save();
    AdvisoryFeedState::of(AdvisoryFeed::Eol)->forceFill(['enabled' => false])->save();
    Http::fake();

    resolve(SyncAdvisories::class)->handle();
    resolve(SyncKnownExploited::class)->handle();
    resolve(SyncLifecycles::class)->handle();

    Http::assertNothingSent();
});

test('KEV is fetched conditionally, replaced as a whole and a broken document keeps the old one', function (): void {
    Http::fake([
        'www.cisa.gov/*' => Http::sequence()
            ->push(['vulnerabilities' => [
                ['cveID' => 'CVE-2021-23337', 'vendorProject' => 'lodash', 'product' => 'lodash', 'vulnerabilityName' => "lodash Command\nInjection", 'dateAdded' => '2026-10-03', 'dueDate' => '2026-10-24', 'knownRansomwareCampaignUse' => 'Known'],
            ]], 200, ['ETag' => '"v1"'])
            ->push('', 304)
            ->push(['vulnerabilities' => [['cveID' => 'not a cve', 'dateAdded' => '2026-10-03']]]),
    ]);

    expect(resolve(SyncKnownExploited::class)->handle())->toBe('updated')
        ->and(DB::table('known_exploited')->first())->toMatchObject(['cve_id' => 'CVE-2021-23337', 'ransomware' => true, 'name' => 'lodash Command Injection'])
        ->and(AdvisoryFeedState::of(AdvisoryFeed::Kev)->etag)->toBe('"v1"');

    expect(resolve(SyncKnownExploited::class)->handle())->toBe('unchanged');
    Http::assertSent(static fn (Request $r): bool => $r->header('If-None-Match') === ['"v1"']);

    expect(resolve(SyncKnownExploited::class)->handle())->toBe('failed')
        ->and(DB::table('known_exploited')->count())->toBe(1)
        ->and(AdvisoryFeedState::of(AdvisoryFeed::Kev)->last_error)->toBe('The KEV catalogue has an entry launchroom can\'t read.');
});

test('endoflife.date cycles are stored for products in use', function (): void {
    DB::table('inventory_components')->insert([
        'inventory_id' => $this->inventory->id, 'ecosystem' => 'binary', 'name' => 'node', 'version' => '20.11.1', 'kind' => 'runtime', 'scope' => 'runtime', 'presence' => 'installed', 'direct' => false,
    ]);
    Http::fake([
        'endoflife.date/api/v1/products/nodejs' => Http::response(['result' => ['name' => 'nodejs', 'releases' => [
            ['name' => '22', 'releaseDate' => '2024-04-24', 'isEol' => false, 'eolFrom' => '2027-04-30', 'latest' => ['name' => '22.11.0', 'date' => '2024-10-29']],
            ['name' => '20', 'releaseDate' => '2023-04-18', 'isEol' => true, 'eolFrom' => '2026-04-30', 'latest' => ['name' => '20.19.5', 'date' => '2025-09-03']],
        ]]]),
        'endoflife.date/api/v1/products/debian' => Http::response(['result' => ['releases' => [['name' => '12', 'releaseDate' => '2023-06-10', 'isEol' => false, 'eolFrom' => '2026-06-10', 'latest' => ['name' => '12.7', 'date' => '2024-08-31']]]]]),
        '*' => Http::response([], 404),
    ]);

    expect(resolve(SyncLifecycles::class)->handle())->toBe(['products' => 2, 'failed' => 0]);

    expect(DB::table('lifecycle_releases')->where('product', 'nodejs')->where('cycle', '20')->first())
        ->toMatchObject(['latest' => '20.19.5', 'is_eol' => true]);
    Http::assertSentCount(2);
});
