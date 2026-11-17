<?php

declare(strict_types=1);

namespace App\Support\Deployments\Failures;

use App\Data\Deployments\FailureExplanationData;
use App\Data\Deployments\RuntimeFindingData;
use App\Enums\DeploymentFailureClass;

/**
 * Problems a live app logs while it serves (G12): requests refused for the
 * domain (DisallowedHost, Rails' blocked hosts, Phoenix's check_origin),
 * forms refused as cross-site, gunicorn worker timeouts. The deploy went
 * green, but visitors hit them. Matched by the failure catalogue's own
 * entries, on single lines, so the words are the same everywhere.
 */
final class RuntimeFindings
{
    /** Catalogue entries that count as a runtime finding. */
    public const array KEYS = ['django_disallowed_host', 'rails_blocked_host', 'phoenix_check_origin', 'csrf_origin', 'gunicorn_timeout'];

    /** A cheap filter before a line goes through the catalogue. */
    private const string CANDIDATE = '/DisallowedHost|Invalid HTTP_HOST|Blocked hosts?:|check origin for Phoenix|CSRF|Origin checking failed|HTTP Origin header|WORKER TIMEOUT/';

    /**
     * The first finding in the lines, or null.
     *
     * @param  list<string>  $lines
     */
    public static function scan(array $lines, string $at): ?RuntimeFindingData
    {
        foreach ($lines as $line) {
            if (preg_match(self::CANDIDATE, $line) !== 1) {
                continue;
            }

            $explanation = FailureCatalogue::explain(new FailureContext(DeploymentFailureClass::RuntimeError, mb_substr($line, 0, 2000)));

            if ($explanation instanceof FailureExplanationData && in_array($explanation->key, self::KEYS, true)) {
                return new RuntimeFindingData(
                    key: $explanation->key,
                    title: $explanation->title,
                    cause: $explanation->cause,
                    fix: $explanation->fix,
                    line: mb_substr(trim($line), 0, 300),
                    at: $at,
                );
            }
        }

        return null;
    }
}
