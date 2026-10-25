<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Enums\Security\InvestigationStatus;
use App\Models\Security\SecurityInvestigation;

/**
 * The short form of an investigation for notifications and cards
 * (docs/22 §9.2, §10): "Checked the container: nothing unusual." Never
 * paths, commands or client networks.
 */
final class InvestigationSummary
{
    /**
     * @return list<string>
     */
    public static function sentences(?SecurityInvestigation $investigation): array
    {
        if (! $investigation instanceof SecurityInvestigation) {
            return [];
        }

        if ($investigation->status === InvestigationStatus::Running) {
            return ['Checking the container now.'];
        }

        $summary = $investigation->report['summary'] ?? [];

        return is_array($summary) ? array_values(array_filter($summary, is_string(...))) : [];
    }
}
