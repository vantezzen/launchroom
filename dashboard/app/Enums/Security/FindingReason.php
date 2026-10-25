<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * Why an entry is "Worth knowing" and not "Action needed" (docs/22 §5.4):
 * the first rule that kept it from the higher level. Values are stored in
 * `security_findings.reasons`: never rename them.
 */
enum FindingReason: string
{
    case Dev = 'dev';
    case Client = 'client';
    case BundledUnknown = 'bundled_unknown';
    case UnknownScope = 'unknown_scope';
    case OsPackage = 'os_package';
    case TlsAtEdge = 'tls_at_edge';
    case ThirdPartyImage = 'third_party_image';
    case LowSeverity = 'low_severity';
    case SeverityUnknown = 'severity_unknown';
    case NoFix = 'no_fix';
    case LocalVector = 'local_vector';
    case NotPublic = 'not_public';
    case Preview = 'preview';
    case EndOfLife = 'end_of_life';
    case PatchAvailable = 'patch_available';

    public function label(): string
    {
        return match ($this) {
            self::Dev => 'Development dependency',
            self::Client => 'Only in browser code',
            self::BundledUnknown => 'In the app bundle; launchroom can’t tell if the server runs it',
            self::UnknownScope => 'Scope unknown (no lockfile)',
            self::OsPackage => 'Operating system package',
            self::TlsAtEdge => 'TLS ends at launchroom’s edge',
            self::ThirdPartyImage => 'Part of the image, waiting for a fixed version from its maintainers',
            self::LowSeverity => 'Low or medium severity',
            self::SeverityUnknown => 'No severity published',
            self::NoFix => 'No fixed version yet',
            self::LocalVector => 'Needs local access to the server',
            self::NotPublic => 'No public domain',
            self::Preview => 'Preview environment',
            self::EndOfLife => 'End of life',
            self::PatchAvailable => 'Newer patch available',
        };
    }

    /**
     * Group heading on the Security page ("142 in operating system packages").
     */
    public function group(): string
    {
        return match ($this) {
            self::Dev => 'in development dependencies',
            self::Client => 'only in browser code',
            self::BundledUnknown => 'in app bundles',
            self::UnknownScope => 'with an unknown scope',
            self::OsPackage => 'in operating system packages',
            self::TlsAtEdge => 'in TLS libraries',
            self::ThirdPartyImage => 'waiting for image maintainers',
            self::LowSeverity => 'with low or medium severity',
            self::SeverityUnknown => 'without a published severity',
            self::NoFix => 'without a fixed version yet',
            self::LocalVector => 'that need local access',
            self::NotPublic => 'in services without a public domain',
            self::Preview => 'in preview environments',
            self::EndOfLife => 'past their end of life',
            self::PatchAvailable => 'with a newer patch',
        };
    }
}
