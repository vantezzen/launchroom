<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * Severity of an advisory group (docs/22 §4.5): the GHSA label, else the
 * highest CVSS v3 base score, else unknown.
 */
enum Severity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Unknown = 'unknown';

    public static function fromLabel(?string $label): ?self
    {
        return match (strtoupper((string) $label)) {
            'CRITICAL' => self::Critical,
            'HIGH' => self::High,
            'MODERATE', 'MEDIUM' => self::Medium,
            'LOW' => self::Low,
            default => null,
        };
    }

    public static function fromScore(?float $score): self
    {
        return match (true) {
            $score === null => self::Unknown,
            $score >= 9.0 => self::Critical,
            $score >= 7.0 => self::High,
            $score >= 4.0 => self::Medium,
            $score > 0.0 => self::Low,
            default => self::Unknown,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
            self::Unknown => 0,
        };
    }

    public function isHighOrCritical(): bool
    {
        return $this === self::High || $this === self::Critical;
    }

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
            self::Unknown => 'Unknown severity',
        };
    }
}
