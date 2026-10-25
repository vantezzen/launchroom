<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * Attack categories of firewall events, from the CRS rule id (docs/22 §6.5;
 * mirrors launchroomd's `state.WafCategoryOf`).
 */
enum WafCategory: string
{
    case Method = 'method';
    case Scanner = 'scanner';
    case Protocol = 'protocol';
    case Lfi = 'lfi';
    case Rfi = 'rfi';
    case Rce = 'rce';
    case Php = 'php';
    case Generic = 'generic';
    case Xss = 'xss';
    case Sqli = 'sqli';
    case SessionFixation = 'session_fixation';
    case Java = 'java';
    case Other = 'other';

    public static function ofRule(int $rule): self
    {
        return match (intdiv($rule, 1000)) {
            911 => self::Method,
            913 => self::Scanner,
            920, 921, 922 => self::Protocol,
            930 => self::Lfi,
            931 => self::Rfi,
            932 => self::Rce,
            933 => self::Php,
            934 => self::Generic,
            941 => self::Xss,
            942 => self::Sqli,
            943 => self::SessionFixation,
            944 => self::Java,
            default => self::Other,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Method => 'Disallowed methods',
            self::Scanner => 'Scanners',
            self::Protocol => 'Protocol abuse',
            self::Lfi => 'File access',
            self::Rfi => 'Remote file inclusion',
            self::Rce => 'Code execution',
            self::Php => 'PHP injection',
            self::Generic => 'Other injections',
            self::Xss => 'Cross-site scripting',
            self::Sqli => 'SQL injection',
            self::SessionFixation => 'Session fixation',
            self::Java => 'Java attacks',
            self::Other => 'Other',
        };
    }

    /**
     * The chart's series (docs/22 §9.2): the four named categories in a
     * fixed palette order, everything else folded into "Other".
     */
    public function chartSeries(): string
    {
        return match ($this) {
            self::Sqli, self::Xss, self::Rce, self::Scanner => $this->value,
            default => 'other',
        };
    }
}
