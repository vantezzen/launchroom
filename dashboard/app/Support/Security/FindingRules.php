<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Enums\Security\FindingLevel;
use App\Enums\Security\FindingReason;
use App\Enums\Security\Severity;

/**
 * The alert-level rules of docs/22 §5.2: a finding is "Action needed" when
 * every rule A1–A6 holds (A7, dismissal, is the finding's status). Known
 * exploited lifts A2c, A3, A4, A5 and A6, never A1, dev or browser scope,
 * or an OS package off the exposed list. When in doubt it is "Worth
 * knowing". Pure: everything it needs is in FindingFacts.
 */
final class FindingRules
{
    public static function evaluate(FindingFacts $facts): FindingVerdict
    {
        $kev = $facts->knownExploited;
        $checks = [];
        $reasons = [];

        // A1: a live deployment outside previews.
        $checks[] = self::check('A1', ! $facts->preview, $facts->preview ? 'Preview environment' : 'In a live deployment outside previews');

        if ($facts->preview) {
            $reasons[] = FindingReason::Preview;
        }

        // A2: the component may run when the service handles requests.
        [$passed, $label, $reason] = self::runs($facts);
        $checks[] = self::check('A2', $passed, $label);

        if ($reason !== null) {
            $reasons[] = $reason;
        }

        // A3: high or critical (the GHSA label, else CVSS ≥ 7.0: resolved
        // into `severity` in that order, docs/22 §4.5), or known exploited.
        $severe = $facts->severity->isHighOrCritical();
        $checks[] = self::check('A3', $severe || $kev, self::severityLabel($facts, $severe));

        if (! $severe && ! $kev) {
            $reasons[] = $facts->severity === Severity::Unknown ? FindingReason::SeverityUnknown : FindingReason::LowSeverity;
        }

        // A4: a fixed version exists, or known exploited.
        $checks[] = self::check('A4', $facts->fixed || $kev, match (true) {
            $facts->fixed => $facts->fixedVersion !== null ? "Fixed in {$facts->fixedVersion}" : 'A fixed version exists',
            $kev => 'No fixed version yet, but it is known to be exploited',
            default => 'No fixed version yet',
        });

        if (! $facts->fixed && ! $kev) {
            $reasons[] = FindingReason::NoFix;
        }

        // A5: reachable over the network (N/A or no vector), or known exploited.
        $network = $facts->attackVector === null || in_array($facts->attackVector, ['N', 'A'], true);
        $checks[] = self::check('A5', $network || $kev, $network ? 'Can be triggered over the network' : 'Needs local access to the server');

        if (! $network && ! $kev) {
            $reasons[] = FindingReason::LocalVector;
        }

        // A6: the service is public, or known exploited.
        $checks[] = self::check('A6', $facts->public || $kev, $facts->public
            ? ($facts->domain !== null ? "Public on {$facts->domain}" : 'Has a public domain')
            : 'No public domain');

        if (! $facts->public && ! $kev) {
            $reasons[] = FindingReason::NotPublic;
        }

        return new FindingVerdict(
            $reasons === [] ? FindingLevel::ActionNeeded : FindingLevel::WorthKnowing,
            $reasons,
            $checks,
        );
    }

    /**
     * A2 (docs/22 §5.2): built images with runtime scope; OS packages only
     * from the exposed list; images launchroom didn't build and unknown
     * scope only when known exploited.
     *
     * @return array{bool, string, FindingReason|null}
     */
    private static function runs(FindingFacts $facts): array
    {
        $kev = $facts->knownExploited;

        if ($facts->isSystemLevel()) {
            if (! ExposedPackages::isExposed($facts->ecosystem, $facts->name)) {
                return ExposedPackages::isTls($facts->name)
                    ? [false, 'TLS ends at launchroom’s edge', FindingReason::TlsAtEdge]
                    : [false, 'Operating system package', FindingReason::OsPackage];
            }

            if (! $facts->builtImage && ! $kev) {
                return [false, 'Part of the image, waiting for a fixed version from its maintainers', FindingReason::ThirdPartyImage];
            }

            return [true, 'Runs your app or handles its requests', null];
        }

        if ($facts->scope === 'dev') {
            return [false, $facts->lockfile !== null ? "Development dependency ({$facts->lockfile})" : 'Development dependency', FindingReason::Dev];
        }

        if ($facts->scope === 'client') {
            return [false, 'Only in browser code', FindingReason::Client];
        }

        if (! $facts->builtImage) {
            return $kev
                ? [true, 'Part of the image, and known to be exploited', null]
                : [false, 'Part of the image, waiting for a fixed version from its maintainers', FindingReason::ThirdPartyImage];
        }

        if ($facts->scope !== 'runtime') {
            if ($kev) {
                return [true, 'Scope unknown, but known to be exploited', null];
            }

            return $facts->presence === 'bundled'
                ? [false, 'In the app bundle; launchroom can’t tell if the server runs it', FindingReason::BundledUnknown]
                : [false, 'Scope unknown (no lockfile)', FindingReason::UnknownScope];
        }

        return [true, $facts->lockfile !== null ? "Runtime dependency ({$facts->lockfile})" : 'Runtime dependency', null];
    }

    private static function severityLabel(FindingFacts $facts, bool $severe): string
    {
        $score = $facts->cvssScore !== null ? ' (CVSS '.number_format($facts->cvssScore, 1).')' : '';

        return match (true) {
            $severe => $facts->severity->label().' severity'.$score,
            $facts->knownExploited => 'Known to be exploited',
            $facts->severity === Severity::Unknown => 'No severity published',
            default => $facts->severity->label().' severity'.$score,
        };
    }

    /**
     * @return array{rule: string, passed: bool, label: string}
     */
    private static function check(string $rule, bool $passed, string $label): array
    {
        return ['rule' => $rule, 'passed' => $passed, 'label' => $label];
    }
}
