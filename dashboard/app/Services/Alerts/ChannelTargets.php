<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Enums\Alerts\AlertChannelKind;
use App\Exceptions\DomainRuleViolation;
use App\Support\Alerts\ChannelUrls;
use SensitiveParameter;

/**
 * Validates what a channel kind needs (docs/15 §3.5): email addresses, or
 * a URL of the kind's grammar. Refusals are field errors for the form.
 */
final readonly class ChannelTargets
{
    public const int MAX_RECIPIENTS = 10;

    /**
     * @param  list<string>|null  $recipients
     * @return list<string>
     */
    public function recipients(?array $recipients): array
    {
        $clean = array_values(array_unique(array_filter(array_map(
            static fn (string $address): string => strtolower(trim($address)),
            $recipients ?? [],
        ), static fn (string $address): bool => $address !== '')));

        if ($clean === []) {
            throw DomainRuleViolation::on('recipients', 'Add at least one email address.');
        }

        if (count($clean) > self::MAX_RECIPIENTS) {
            throw DomainRuleViolation::on('recipients', 'Up to '.self::MAX_RECIPIENTS.' addresses per channel.');
        }

        foreach ($clean as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw DomainRuleViolation::on('recipients', "{$address} doesn't look like an email address.");
            }
        }

        return $clean;
    }

    public function url(AlertChannelKind $kind, #[SensitiveParameter] ?string $url): string
    {
        $url = trim((string) $url);

        $ok = match ($kind) {
            AlertChannelKind::Slack => ChannelUrls::isSlack($url),
            AlertChannelKind::Discord => ChannelUrls::isDiscord($url),
            AlertChannelKind::Webhook => ChannelUrls::isWebhook($url),
            AlertChannelKind::Email => false,
        };

        if (! $ok) {
            throw DomainRuleViolation::on('secretUrl', match ($kind) {
                AlertChannelKind::Slack => 'Paste a Slack incoming webhook URL: https://hooks.slack.com/services/…',
                AlertChannelKind::Discord => 'Paste a Discord webhook URL: https://discord.com/api/webhooks/…',
                default => 'Enter an https URL without a user name or password.',
            });
        }

        return $url;
    }
}
