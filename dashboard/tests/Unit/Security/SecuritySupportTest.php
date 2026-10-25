<?php

declare(strict_types=1);

use App\Data\Security\InvestigationLineData;
use App\Data\Security\InvestigationStepData;
use App\Enums\Security\WafCategory;
use App\Queries\Security\InventorySearch;
use App\Queries\Security\WafOverview;
use App\Support\Security\AdvisoryGroups;
use App\Support\Security\ClientNetwork;
use App\Support\Security\Cvss3;
use App\Support\Security\CweCategories;
use App\Support\Security\InvestigationInputs;
use App\Support\Security\InvestigationReport;
use App\Support\Security\LifecycleProducts;
use App\Support\Security\Lockfiles;
use App\Support\Security\OsvPackage;
use App\Support\Security\OsvRecord;
use App\Support\Security\VersionOrder;
use Carbon\CarbonImmutable;

test('CVSS v3 base scores follow the specification', function (string $vector, float $score, string $av): void {
    expect(Cvss3::baseScore($vector))->toBe($score)->and(Cvss3::attackVector($vector))->toBe($av);
})->with([
    ['CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H', 9.8, 'N'],
    ['CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:C/C:H/I:H/A:H', 10.0, 'N'],
    ['CVSS:3.1/AV:N/AC:L/PR:N/UI:R/S:U/C:L/I:L/A:N', 5.4, 'N'],
    ['CVSS:3.0/AV:L/AC:L/PR:L/UI:N/S:U/C:H/I:H/A:H', 7.8, 'L'],
    ['CVSS:3.1/AV:N/AC:H/PR:N/UI:N/S:U/C:N/I:N/A:N', 0.0, 'N'],
    ['CVSS:3.1/AV:A/AC:L/PR:L/UI:N/S:C/C:L/I:L/A:N', 5.4, 'A'],
]);

test('malformed CVSS vectors have no score', function (string $vector): void {
    expect(Cvss3::baseScore($vector))->toBeNull();
})->with(['CVSS:2.0/AV:N', 'CVSS:3.1/AV:X/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H', 'AV:N/AC:L', 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H']);

test('OSV packages: languages by name, OS packages by source package and distro', function (string $eco, string $name, string $version, ?string $purl, ?array $expected): void {
    expect(OsvPackage::for($eco, $name, $version, $purl, ['id' => 'debian', 'versionId' => '12']))->toBe($expected);
})->with([
    'npm' => ['npm', '@radix-ui/react-dialog', '1.1.4', 'pkg:npm/%40radix-ui/react-dialog@1.1.4', ['ecosystem' => 'npm', 'name' => '@radix-ui/react-dialog', 'version' => '1.1.4']],
    'go stdlib' => ['go', 'stdlib', 'go1.22.3', null, ['ecosystem' => 'Go', 'name' => 'stdlib', 'version' => '1.22.3']],
    'go module' => ['go', 'golang.org/x/net', 'v0.23.0', null, ['ecosystem' => 'Go', 'name' => 'golang.org/x/net', 'version' => '0.23.0']],
    'debian source' => ['deb', 'libssl3', '3.0.15-1~deb12u1', 'pkg:deb/debian/libssl3@3.0.15-1~deb12u1?arch=amd64&distro=debian-12&upstream=openssl', ['ecosystem' => 'Debian:12', 'name' => 'openssl', 'version' => '3.0.15-1~deb12u1']],
    'debian upstream version' => ['deb', 'libc6', '2.36-9+deb12u9', 'pkg:deb/debian/libc6@2.36-9%2Bdeb12u9?distro=debian-12&upstream=glibc%402.36-9%2Bdeb12u9', ['ecosystem' => 'Debian:12', 'name' => 'glibc', 'version' => '2.36-9+deb12u9']],
    'ubuntu lts' => ['deb', 'curl', '8.5.0-2ubuntu10.4', 'pkg:deb/ubuntu/curl@8.5.0-2ubuntu10.4?distro=ubuntu-24.04', ['ecosystem' => 'Ubuntu:24.04:LTS', 'name' => 'curl', 'version' => '8.5.0-2ubuntu10.4']],
    'ubuntu interim' => ['deb', 'curl', '8.5.0', 'pkg:deb/ubuntu/curl@8.5.0?distro=ubuntu-23.10', ['ecosystem' => 'Ubuntu:23.10', 'name' => 'curl', 'version' => '8.5.0']],
    'alpine origin' => ['apk', 'libcrypto3', '3.3.2-r0', 'pkg:apk/alpine/libcrypto3@3.3.2-r0?distro=alpine-3.20.3&upstream=openssl', ['ecosystem' => 'Alpine:v3.20', 'name' => 'openssl', 'version' => '3.3.2-r0']],
    'rpm without an OSV ecosystem' => ['rpm', 'openssl', '3.0.7', 'pkg:rpm/fedora/openssl@3.0.7?distro=fedora-40', null],
    'rpm on rocky' => ['rpm', 'openssl', '3.0.7', 'pkg:rpm/rocky/openssl@3.0.7?distro=rocky-9.3', ['ecosystem' => 'Rocky Linux', 'name' => 'openssl', 'version' => '3.0.7']],
    'node binary' => ['binary', 'node', '22.11.0', 'pkg:generic/node@22.11.0', ['ecosystem' => 'Bitnami', 'name' => 'node', 'version' => '22.11.0']],
    'unknown binary' => ['binary', 'busybox', '1.36.1', null, null],
    'deb from the image distro' => ['deb', 'zlib1g', '1.2.13', null, ['ecosystem' => 'Debian:12', 'name' => 'zlib1g', 'version' => '1.2.13']],
]);

test('fixed versions: the smallest one above the installed version', function (): void {
    expect(VersionOrder::nextFixed('npm', '4.17.20', ['4.17.21', '5.0.0', '3.9.0']))->toBe('4.17.21')
        ->and(VersionOrder::nextFixed('go', 'v0.23.0', ['0.23.1', 'v0.30.0']))->toBe('0.23.1')
        ->and(VersionOrder::nextFixed('npm', '1.2.3-rc.1', ['1.2.3']))->toBe('1.2.3')
        ->and(VersionOrder::nextFixed('deb', '1.0', ['1.1']))->toBeNull()
        // A runtime binary matched in Bitnami's data (dev stack joint run): one fix per release line.
        ->and(VersionOrder::nextFixed('binary', '22.23.3', ['22.23.4', '24.22.1', '26.12.1']))->toBe('22.23.4')
        ->and(VersionOrder::nextFixed('binary', '24.0.0', ['22.23.4', '24.22.1', '26.12.1']))->toBe('24.22.1')
        ->and(VersionOrder::compare('1.10.0', '1.9.9'))->toBeGreaterThan(0)
        ->and(VersionOrder::compare('1.0.0-alpha', '1.0.0-alpha.1'))->toBeLessThan(0);
});

test('advisory groups prefer the GHSA id, then the CVE', function (): void {
    expect(AdvisoryGroups::key('PYSEC-2024-1', ['GHSA-xxxx-yyyy-zzzz', 'CVE-2024-1234'], []))->toBe('GHSA-xxxx-yyyy-zzzz')
        ->and(AdvisoryGroups::key('DEBIAN-CVE-2024-1', [], ['CVE-2024-0001']))->toBe('CVE-2024-0001')
        ->and(AdvisoryGroups::key('RUSTSEC-2024-1', [], []))->toBe('RUSTSEC-2024-1');
});

test('OSV records keep the matched fields as plain data', function (): void {
    $record = OsvRecord::parse([
        'id' => 'GHSA-35jh-r3h4-6jhm',
        'aliases' => ['CVE-2021-23337', 'bad id with spaces'],
        'modified' => '2024-03-01T10:00:00.123Z',
        'published' => '2021-02-15T00:00:00Z',
        'summary' => "Command Injection\nin lodash",
        'details' => "Line one.\n\n<script>alert(1)</script>",
        'severity' => [['type' => 'CVSS_V3', 'score' => 'CVSS:3.1/AV:N/AC:L/PR:H/UI:N/S:U/C:H/I:H/A:H'], ['type' => 'CVSS_V4', 'score' => 'CVSS:4.0/AV:N/AC:L/AT:N/PR:H/UI:N/VC:H/VI:H/VA:H/SC:N/SI:N/SA:N']],
        'database_specific' => ['severity' => 'HIGH', 'cwe_ids' => ['CWE-77', 'CWE-94', 'nope']],
        'affected' => [['package' => ['ecosystem' => 'npm', 'name' => 'lodash'], 'ranges' => [['type' => 'SEMVER', 'events' => [['introduced' => '0'], ['fixed' => '4.17.21']]]]]],
        'references' => [['type' => 'ADVISORY', 'url' => 'https://nvd.nist.gov/vuln/detail/CVE-2021-23337'], ['type' => 'WEB', 'url' => 'javascript:alert(1)'], ['type' => 'WEB', 'url' => 'http://insecure.example.com']],
    ]);

    expect($record['group_key'])->toBe('GHSA-35jh-r3h4-6jhm')
        ->and($record['aliases'])->toBe(['CVE-2021-23337'])
        ->and($record['cve_ids'])->toBe(['CVE-2021-23337'])
        ->and($record['summary'])->toBe('Command Injection in lodash')
        ->and($record['details'])->toBe("Line one.\n\n<script>alert(1)</script>")
        ->and($record['severity_label'])->toBe('HIGH')
        ->and($record['cvss']['v3Score'])->toBe(7.2)
        ->and($record['cvss'])->toHaveKey('v4')
        ->and($record['attack_vector'])->toBe('N')
        ->and($record['cwe_ids'])->toBe(['CWE-77', 'CWE-94'])
        ->and($record['affected'])->toBe([['ecosystem' => 'npm', 'name' => 'lodash', 'fixed' => ['4.17.21']]])
        ->and(array_column($record['references'], 'url'))->toBe(['https://nvd.nist.gov/vuln/detail/CVE-2021-23337'])
        ->and(OsvRecord::parse(['id' => 'has spaces']))->toBeNull();
});

test('client networks are canonical /24 or /48 prefixes only', function (string $value, bool $valid): void {
    expect(ClientNetwork::isValid($value))->toBe($valid);
})->with([
    ['203.0.113.0/24', true],
    ['2001:db8::/48', true],
    ['2001:db8:17::/48', true],
    ['203.0.113.7/24', false],
    ['203.0.113.0/32', false],
    ['203.0.113.7', false],
    ['2001:db8::1/48', false],
    ['2001:0db8::/48', false],
    ['::ffff:203.0.113.0/48', false],
    ['010.0.0.0/24', false],
]);

test('CWE ids map to the firewall categories that show attempts', function (): void {
    expect(CweCategories::for(['CWE-89']))->toBe([WafCategory::Sqli])
        ->and(CweCategories::for(['CWE-79', 'CWE-22']))->toBe([WafCategory::Xss, WafCategory::Lfi])
        ->and(CweCategories::for(['CWE-77']))->toBe([WafCategory::Rce, WafCategory::Generic])
        ->and(CweCategories::for(['CWE-400']))->toBe([])
        ->and(CweCategories::for([]))->toBe([]);
});

test('release cycles are read per product', function (): void {
    expect(LifecycleProducts::cycle('nodejs', '22.11.0'))->toBe('22')
        ->and(LifecycleProducts::cycle('python', '3.12.4'))->toBe('3.12')
        ->and(LifecycleProducts::cycle('go', 'go1.22.3'))->toBe('1.22')
        ->and(LifecycleProducts::cycle('debian', '12'))->toBe('12')
        ->and(LifecycleProducts::cycle('ubuntu', '24.04'))->toBe('24.04')
        ->and(LifecycleProducts::cycle('postgresql', '16'))->toBe('16')
        ->and(LifecycleProducts::cycle('mysql', '8'))->toBeNull()
        ->and(LifecycleProducts::forRuntime('binary', 'node'))->toBe('nodejs')
        ->and(LifecycleProducts::forRuntime('go', 'golang.org/x/net'))->toBeNull();
});

test('stacked firewall bars share a request out over its categories', function (): void {
    expect(WafOverview::apportion(10, ['sqli' => 10, 'xss' => 10]))->toBe(['sqli' => 5, 'xss' => 5])
        ->and(WafOverview::apportion(3, ['sqli' => 3]))->toBe(['sqli' => 3])
        ->and(array_sum(WafOverview::apportion(7, ['sqli' => 4, 'xss' => 3, 'other' => 2])))->toBe(7)
        ->and(WafOverview::apportion(0, ['sqli' => 1]))->toBe([]);
});

test('firewall exceptions from an event cut the path to its static prefix', function (): void {
    $draft = WafOverview::draft([949110, 942100], '/api/products/:id', ['ARGS_GET:id', 'REQUEST_HEADERS:User-Agent'], '/p/x/y/s/z');

    expect($draft?->rule)->toBe(942100)
        ->and($draft?->path)->toBe('/api/products')
        ->and($draft?->target)->toBe('ARGS:id')
        ->and(WafOverview::draft([949110], '/', [], '/p'))->toBeNull()
        ->and(WafOverview::draft([913100], '/:id', [], '/p')?->path)->toBeNull();
});

test('the inventory query grammar reads words, versions and filters', function (): void {
    expect(InventorySearch::parse('node 20 ecosystem:binary'))->toBe(['words' => ['node'], 'version' => '20', 'ecosystem' => 'binary', 'service' => null, 'scope' => null])
        ->and(InventorySearch::parse('openssl 3.0.15 service:api scope:runtime'))->toBe(['words' => ['openssl'], 'version' => '3.0.15', 'ecosystem' => null, 'service' => 'api', 'scope' => 'runtime'])
        ->and(InventorySearch::parse('lodash, v4.17'))->toBe(['words' => ['lodash'], 'version' => '4.17', 'ecosystem' => null, 'service' => null, 'scope' => null]);
});

test('investigation reports list console and SSH sessions once per person', function (): void {
    $now = CarbonImmutable::parse('2026-10-25 12:00:00');
    $session = static fn (string $kind, string $user, int $hoursAgo): array => ['kind' => $kind, 'user' => $user, 'from' => $now->subHours($hoursAgo), 'to' => $now->subHours($hoursAgo)->addMinutes(5)];
    $report = InvestigationReport::build(new InvestigationInputs(
        service: 'api',
        now: $now,
        replicas: [],
        sessions: [$session('console', 'Ada', 30), $session('console', 'Ada', 3), $session('ssh', 'Ada', 2), $session('console', 'Linus', 1)],
        wafMode: 'block',
        categories: [],
        events: [],
        wafTotals: ['blocked' => 0, 'detected' => 0],
        hours: [],
        liveSince: null,
        connections: [],
        secrets: [],
        volumes: [],
        domains: [],
        finding: null,
        hrefs: [],
    ));

    $lines = array_values(array_filter(array_map(static fn (InvestigationLineData $line): string => $line->text, $report->found), static fn (string $text): bool => str_contains($text, 'session')));

    expect($lines)->toBe([
        'Ada opened 2 console sessions and 1 SSH session, the last 10:00 to 10:05.',
        'Console session by Linus, 11:00 to 11:05.',
    ]);
});

test('investigation next steps: a runtime binary is raised in the build, a package with its manager', function (): void {
    $steps = static fn (string $ecosystem, string $name): array => array_map(static fn (InvestigationStepData $step): array => [$step->text, $step->command], array_values(array_filter(InvestigationReport::build(new InvestigationInputs(
        service: 'web', now: CarbonImmutable::parse('2026-10-25 12:00:00'), replicas: [], sessions: [], wafMode: 'block', categories: [], events: [],
        wafTotals: ['blocked' => 0, 'detected' => 0], hours: [], liveSince: null, connections: [], secrets: [], volumes: [], domains: [],
        finding: ['name' => $name, 'version' => '1', 'ecosystem' => $ecosystem, 'fixed' => $ecosystem === 'binary' ? '22.23.4' : '4.17.21', 'groupKey' => 'GHSA-x', 'built' => true, 'lockfiles' => ['npm'], 'references' => []],
        hrefs: [],
    ))->steps, static fn (InvestigationStepData $step): bool => $step->kind === 'update_package')));

    expect($steps('binary', 'node'))->toBe([['Build with node 22.23.4 or later: raise the version your repository sets for it, then push.', null]])
        ->and($steps('npm', 'lodash'))->toBe([['Update lodash to 4.17.21 or later in your repository; the push deploys it.', 'npm install lodash@^4.17.21']]);
});

test('Bitnami runtime records: summary from the details, CVSS v4 and the attack vector from the affected package', function (): void {
    // Recorded from osv.dev in the dev stack's joint run (docs/22 §16.3).
    $record = OsvRecord::parse([
        'id' => 'BIT-node-2026-111497',
        'details' => 'A flaw in Node.js can cause Node.js Brotli decoder out-of-bounds write permits remote code execution. This vulnerability affects Node.js 22.x, 24.x, and 26.x.',
        'aliases' => ['BIT-node-min-2026-111497', 'CVE-2026-111497', 'GHSA-9v8h-qqgm-4m35'],
        'modified' => '2026-10-24T10:55:42.890666980Z',
        'published' => '2026-10-23T08:48:26.837Z',
        'database_specific' => ['severity' => 'Critical'],
        'affected' => [[
            'package' => ['name' => 'node', 'ecosystem' => 'Bitnami', 'purl' => 'pkg:bitnami/node'],
            'ranges' => [['type' => 'SEMVER', 'events' => [['introduced' => '0'], ['fixed' => '22.23.4'], ['introduced' => '23.0.0'], ['fixed' => '24.22.1'], ['introduced' => '26.0.0'], ['fixed' => '26.12.1']]]],
            'severity' => [['type' => 'CVSS_V4', 'score' => 'CVSS:4.0/AV:N/AC:L/AT:P/PR:N/UI:N/VC:H/VI:H/VA:H/SC:N/SI:N/SA:N']],
        ]],
    ]);

    expect($record)->not->toBeNull()
        ->and($record['summary'] ?? null)->toBe('A flaw in Node.js can cause Node.js Brotli decoder out-of-bounds write permits remote code execution.')
        ->and($record['severity_label'] ?? null)->toBe('CRITICAL')
        ->and($record['cvss'] ?? null)->toBe(['v4' => 'CVSS:4.0/AV:N/AC:L/AT:P/PR:N/UI:N/VC:H/VI:H/VA:H/SC:N/SI:N/SA:N'])
        ->and($record['attack_vector'] ?? null)->toBe('N')
        ->and($record['affected'][0]['fixed'] ?? null)->toBe(['22.23.4', '24.22.1', '26.12.1']);
});

test('a finding names the lockfile of its ecosystem, never one for a runtime', function (): void {
    $files = [['path' => 'package.json', 'kind' => 'npm', 'packages' => 2], ['path' => 'package-lock.json', 'kind' => 'npm', 'packages' => 2], ['path' => 'requirements.txt', 'kind' => 'requirements', 'packages' => 3]];

    expect(Lockfiles::evidence('npm', 'library', $files))->toBe('package-lock.json')
        ->and(Lockfiles::evidence('pypi', 'library', $files))->toBe('requirements.txt')
        ->and(Lockfiles::evidence('binary', 'runtime', $files))->toBeNull()
        ->and(Lockfiles::evidence('deb', 'os', $files))->toBeNull()
        ->and(Lockfiles::evidence('npm', 'library', [['path' => 'package.json', 'kind' => 'npm', 'packages' => 2]]))->toBe('package.json');
});

test('runtime versions are matched and shown as upstream versions', function (string $ecosystem, string $raw, string $upstream, ?string $cycle): void {
    expect(LifecycleProducts::upstreamVersion($ecosystem, $raw))->toBe($upstream)
        ->and(LifecycleProducts::cycle('nginx', LifecycleProducts::upstreamVersion($ecosystem, $raw)))->toBe($cycle);
})->with([
    'Debian revision (dev stack joint run)' => ['deb', '1.31.8-1~trixie', '1.31.8', '1.31'],
    'Debian epoch and security revision' => ['deb', '1:1.26.3-3+deb13u1', '1.26.3', '1.26'],
    'Debian upstream suffix' => ['deb', '1.24.0+dfsg-2', '1.24.0', '1.24'],
    'Alpine' => ['apk', '1.26.2-r0', '1.26.2', '1.26'],
    'RPM release' => ['rpm', '1.20.1-14.el9', '1.20.1', '1.20'],
    'binary keeps its version' => ['binary', '1.27.2', '1.27.2', '1.27'],
    'leading v' => ['binary', 'v1.27.2', '1.27.2', '1.27'],
]);

test('Go toolchains lose their prefix', function (): void {
    expect(LifecycleProducts::upstreamVersion('go', 'go1.22.3'))->toBe('1.22.3');
});
