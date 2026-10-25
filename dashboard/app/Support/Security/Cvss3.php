<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * CVSS v3.0/v3.1 base scores computed from the vector (docs/22 §4.5), per
 * the FIRST specification (section 7.1 and Appendix A's Roundup). Pure.
 */
final class Cvss3
{
    private const string PATTERN = '#^CVSS:3\.[01]/(?:[A-Za-z]{1,3}:[A-Za-z]/?)+$#D';

    private const array AV = ['N' => 0.85, 'A' => 0.62, 'L' => 0.55, 'P' => 0.2];

    private const array AC = ['L' => 0.77, 'H' => 0.44];

    private const array UI = ['N' => 0.85, 'R' => 0.62];

    private const array CIA = ['H' => 0.56, 'L' => 0.22, 'N' => 0.0];

    /**
     * The base metrics of a vector, or null when it isn't a valid v3 vector.
     *
     * @return array{AV: string, AC: string, PR: string, UI: string, S: string, C: string, I: string, A: string}|null
     */
    public static function metrics(string $vector): ?array
    {
        if (strlen($vector) > 200 || preg_match(self::PATTERN, $vector) !== 1) {
            return null;
        }

        $metrics = [];

        foreach (array_slice(explode('/', rtrim($vector, '/')), 1) as $part) {
            [$key, $value] = explode(':', $part, 2);
            $metrics[$key] = $value;
        }

        foreach (['AV' => self::AV, 'AC' => self::AC, 'UI' => self::UI, 'C' => self::CIA, 'I' => self::CIA, 'A' => self::CIA] as $key => $allowed) {
            if (! isset($metrics[$key], $allowed[$metrics[$key]])) {
                return null;
            }
        }

        if (! in_array($metrics['PR'] ?? null, ['N', 'L', 'H'], true) || ! in_array($metrics['S'] ?? null, ['U', 'C'], true)) {
            return null;
        }

        return [
            'AV' => $metrics['AV'], 'AC' => $metrics['AC'], 'PR' => $metrics['PR'], 'UI' => $metrics['UI'],
            'S' => $metrics['S'], 'C' => $metrics['C'], 'I' => $metrics['I'], 'A' => $metrics['A'],
        ];
    }

    public static function baseScore(string $vector): ?float
    {
        $m = self::metrics($vector);

        if ($m === null) {
            return null;
        }

        $changed = $m['S'] === 'C';
        $pr = match ($m['PR']) {
            'N' => 0.85,
            'L' => $changed ? 0.68 : 0.62,
            default => $changed ? 0.5 : 0.27,
        };

        $iss = 1 - ((1 - self::CIA[$m['C']]) * (1 - self::CIA[$m['I']]) * (1 - self::CIA[$m['A']]));
        $impact = $changed ? 7.52 * ($iss - 0.029) - 3.25 * (($iss - 0.02) ** 15) : 6.42 * $iss;
        $exploitability = 8.22 * self::AV[$m['AV']] * self::AC[$m['AC']] * $pr * self::UI[$m['UI']];

        if ($impact <= 0) {
            return 0.0;
        }

        return self::roundUp(min(($changed ? 1.08 : 1.0) * ($impact + $exploitability), 10.0));
    }

    public static function attackVector(string $vector): ?string
    {
        return self::metrics($vector)['AV'] ?? null;
    }

    /**
     * Appendix A: the smallest number with one decimal that is ≥ the input,
     * computed on integers to avoid floating-point artefacts.
     */
    private static function roundUp(float $value): float
    {
        $int = (int) round($value * 100000);

        if ($int % 10000 === 0) {
            return $int / 100000.0;
        }

        return (floor($int / 10000) + 1) / 10.0;
    }
}
