<?php

declare(strict_types=1);

namespace App\Models\Security;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One OSV vulnerability record (docs/22 §4.3): public data, instance-wide,
 * kept while a query matches it (30 days after the last match). Text
 * fields are data only and rendered as plain text.
 *
 * @property string $id OSV id (GHSA-…, CVE-…, PYSEC-…, DEBIAN-…)
 * @property string $group_key the advisory group's display id (GHSA, else CVE, else the id)
 * @property list<string> $aliases
 * @property list<string> $upstream
 * @property list<string> $cve_ids every CVE among id, aliases and upstream
 * @property Carbon|null $modified
 * @property Carbon|null $published
 * @property Carbon|null $withdrawn
 * @property string|null $summary
 * @property string|null $details
 * @property string|null $severity_label GHSA label (LOW, MODERATE, HIGH, CRITICAL)
 * @property array{v3?: string, v3Score?: float, v4?: string} $cvss
 * @property string|null $attack_vector N, A, L or P
 * @property list<string> $cwe_ids
 * @property list<array{ecosystem: string, name: string, fixed: list<string>}> $affected
 * @property list<array{type: string, url: string}> $references
 * @property Carbon $fetched_at
 * @property Carbon|null $matched_at
 */
#[Fillable(['id', 'group_key', 'aliases', 'upstream', 'cve_ids', 'modified', 'published', 'withdrawn', 'summary', 'details', 'severity_label', 'cvss', 'attack_vector', 'cwe_ids', 'affected', 'references', 'fetched_at', 'matched_at'])]
#[Table(name: 'advisories', key: 'id', keyType: 'string', incrementing: false, timestamps: false)]
final class Advisory extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'upstream' => 'array',
            'cve_ids' => 'array',
            'modified' => 'datetime',
            'published' => 'datetime',
            'withdrawn' => 'datetime',
            'cvss' => 'array',
            'cwe_ids' => 'array',
            'affected' => 'array',
            'references' => 'array',
            'fetched_at' => 'datetime',
            'matched_at' => 'datetime',
        ];
    }
}
