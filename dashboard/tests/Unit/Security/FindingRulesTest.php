<?php

declare(strict_types=1);

use App\Enums\Security\FindingLevel;
use App\Enums\Security\FindingReason;
use App\Enums\Security\Severity;
use App\Support\Security\FindingFacts;
use App\Support\Security\FindingRules;

/*
| The alert-level rules of docs/22 §5.2 as a table: one case per rule A1–A6
| and per reason code, the known-exploited lifts and what they never lift.
*/

/**
 * @param  array<string, mixed>  $overrides
 */
function findingFacts(array $overrides = []): FindingFacts
{
    return new FindingFacts(...[
        'ecosystem' => 'npm',
        'name' => 'lodash',
        'kind' => 'library',
        'scope' => 'runtime',
        'presence' => 'installed',
        'builtImage' => true,
        'preview' => false,
        'public' => true,
        'severity' => Severity::Critical,
        'cvssScore' => 9.8,
        'fixed' => true,
        'attackVector' => 'N',
        'knownExploited' => false,
        'fixedVersion' => '4.17.21',
        'domain' => 'shop.example.com',
        'lockfile' => 'pnpm-lock.yaml',
        ...$overrides,
    ]);
}

test('a runtime dependency with a fixed, network-reachable critical advisory in a public service needs action', function (): void {
    $verdict = FindingRules::evaluate(findingFacts());

    expect($verdict->level)->toBe(FindingLevel::ActionNeeded)
        ->and($verdict->reasons)->toBe([])
        ->and(array_column($verdict->checks, 'passed'))->toBe([true, true, true, true, true, true])
        ->and(array_column($verdict->checks, 'label'))->toBe([
            'In a live deployment outside previews',
            'Runtime dependency (pnpm-lock.yaml)',
            'Critical severity (CVSS 9.8)',
            'Fixed in 4.17.21',
            'Can be triggered over the network',
            'Public on shop.example.com',
        ]);
});

test('each rule keeps a finding at "Worth knowing" with its reason', function (array $overrides, FindingReason $reason): void {
    $verdict = FindingRules::evaluate(findingFacts($overrides));

    expect($verdict->level)->toBe(FindingLevel::WorthKnowing)
        ->and($verdict->reasons[0])->toBe($reason);
})->with([
    'A1 preview' => [['preview' => true], FindingReason::Preview],
    'A2a dev scope' => [['scope' => 'dev'], FindingReason::Dev],
    'A2a browser code' => [['scope' => 'client', 'presence' => 'bundled'], FindingReason::Client],
    'A2c bundled, unknown scope' => [['scope' => 'unknown', 'presence' => 'bundled'], FindingReason::BundledUnknown],
    'A2c unknown scope' => [['scope' => 'unknown'], FindingReason::UnknownScope],
    'A2c image launchroom did not build' => [['builtImage' => false, 'scope' => 'unknown'], FindingReason::ThirdPartyImage],
    'A2b OS library off the exposed list' => [['ecosystem' => 'deb', 'name' => 'libc6', 'kind' => 'os'], FindingReason::OsPackage],
    'A2b OpenSSL: TLS ends at the edge' => [['ecosystem' => 'deb', 'name' => 'libssl3', 'kind' => 'os'], FindingReason::TlsAtEdge],
    'A2c exposed OS package of a third-party image' => [['ecosystem' => 'deb', 'name' => 'nodejs', 'kind' => 'runtime', 'builtImage' => false], FindingReason::ThirdPartyImage],
    'A3 medium severity' => [['severity' => Severity::Medium, 'cvssScore' => 5.3], FindingReason::LowSeverity],
    'A3 no severity published' => [['severity' => Severity::Unknown, 'cvssScore' => null], FindingReason::SeverityUnknown],
    'A4 no fix yet' => [['fixed' => false, 'fixedVersion' => null], FindingReason::NoFix],
    'A5 local vector' => [['attackVector' => 'L'], FindingReason::LocalVector],
    'A5 physical vector' => [['attackVector' => 'P'], FindingReason::LocalVector],
    'A6 no public domain' => [['public' => false, 'domain' => null], FindingReason::NotPublic],
]);

test('the GHSA label wins over a higher CVSS score', function (): void {
    // Severity is resolved label-first (docs/22 §4.5): Moderate stays moderate.
    $verdict = FindingRules::evaluate(findingFacts(['severity' => Severity::Medium, 'cvssScore' => 7.5]));

    expect($verdict->level)->toBe(FindingLevel::WorthKnowing)
        ->and($verdict->reasons)->toBe([FindingReason::LowSeverity]);
});

test('adjacent network vectors and missing vectors count as reachable', function (?string $vector): void {
    expect(FindingRules::evaluate(findingFacts(['attackVector' => $vector]))->level)->toBe(FindingLevel::ActionNeeded);
})->with(['A', null]);

test('known exploited lifts the third-party, unknown scope, severity, fix, vector and exposure rules', function (array $overrides): void {
    $verdict = FindingRules::evaluate(findingFacts([...$overrides, 'knownExploited' => true]));

    expect($verdict->level)->toBe(FindingLevel::ActionNeeded)->and($verdict->reasons)->toBe([]);
})->with([
    'A2c image launchroom did not build' => [['builtImage' => false, 'scope' => 'unknown']],
    'A2c unknown scope' => [['scope' => 'unknown', 'presence' => 'bundled']],
    'A2c exposed OS package of a third-party image' => [['ecosystem' => 'deb', 'name' => 'nginx', 'kind' => 'runtime', 'builtImage' => false]],
    'A3 low severity' => [['severity' => Severity::Low, 'cvssScore' => 3.1]],
    'A4 no fix' => [['fixed' => false, 'fixedVersion' => null]],
    'A5 local vector' => [['attackVector' => 'L']],
    'A6 not public' => [['public' => false, 'domain' => null]],
    'all of them at once' => [['builtImage' => false, 'scope' => 'unknown', 'severity' => Severity::Unknown, 'cvssScore' => null, 'fixed' => false, 'attackVector' => 'L', 'public' => false]],
]);

test('known exploited never lifts a preview, dev or browser scope, or an unexposed OS package', function (array $overrides, FindingReason $reason): void {
    $verdict = FindingRules::evaluate(findingFacts([...$overrides, 'knownExploited' => true]));

    expect($verdict->level)->toBe(FindingLevel::WorthKnowing)->and($verdict->reasons)->toBe([$reason]);
})->with([
    'A1 preview' => [['preview' => true], FindingReason::Preview],
    'dev scope' => [['scope' => 'dev'], FindingReason::Dev],
    'browser code' => [['scope' => 'client'], FindingReason::Client],
    'OS library' => [['ecosystem' => 'apk', 'name' => 'zlib', 'kind' => 'os'], FindingReason::OsPackage],
    'OpenSSL' => [['ecosystem' => 'deb', 'name' => 'openssl', 'kind' => 'os'], FindingReason::TlsAtEdge],
]);

test('runtimes found as binaries and Go stdlib are judged by the exposed list', function (string $ecosystem, string $name, FindingLevel $level): void {
    expect(FindingRules::evaluate(findingFacts(['ecosystem' => $ecosystem, 'name' => $name, 'kind' => 'runtime']))->level)->toBe($level);
})->with([
    ['binary', 'node', FindingLevel::ActionNeeded],
    ['go', 'stdlib', FindingLevel::ActionNeeded],
    ['deb', 'python3.11', FindingLevel::ActionNeeded],
    ['deb', 'php8.3-fpm', FindingLevel::ActionNeeded],
    ['deb', 'openjdk-17-jre-headless', FindingLevel::ActionNeeded],
    ['binary', 'busybox', FindingLevel::WorthKnowing],
]);

test('every failing rule is listed in order, the first is shown', function (): void {
    $verdict = FindingRules::evaluate(findingFacts(['scope' => 'dev', 'severity' => Severity::Low, 'fixed' => false, 'public' => false]));

    expect($verdict->reasons)->toBe([FindingReason::Dev, FindingReason::LowSeverity, FindingReason::NoFix, FindingReason::NotPublic])
        ->and($verdict->checks[1])->toBe(['rule' => 'A2', 'passed' => false, 'label' => 'Development dependency (pnpm-lock.yaml)']);
});
