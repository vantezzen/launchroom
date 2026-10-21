<?php

declare(strict_types=1);

namespace App\Data\Git;

use App\Models\Git\GitHubApp;
use App\Models\Git\GitHubInstallation;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * A GitHub App on the Git settings page. No secrets: they are write-only.
 */
final class GitHubAppData extends Data
{
    /**
     * @param  list<GitHubInstallationData>  $installations
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public ?string $ownerLogin,
        public string $htmlUrl,
        public string $installUrl,
        public string $webUrl,
        public bool $isEnterprise,
        public ?CarbonInterface $createdAt,
        public WebhookHealthData $webhook,
        public array $installations,
        public bool $canManage,
    ) {}

    /**
     * Expects active installations (with `repositories_count`) and the latest
     * delivery loaded.
     */
    public static function fromApp(GitHubApp $app, bool $canManage): self
    {
        return new self(
            $app->public_id,
            $app->name,
            $app->slug,
            $app->owner_login,
            $app->htmlUrl(),
            $app->installUrl(),
            $app->web_url,
            rtrim($app->web_url, '/') !== rtrim(config()->string('launchroom.github.web_url'), '/'),
            $app->created_at,
            WebhookHealthData::fromApp($app),
            array_values($app->installations
                ->map(fn (GitHubInstallation $installation): GitHubInstallationData => GitHubInstallationData::fromInstallation($installation->setRelation('app', $app)))
                ->all()),
            $canManage,
        );
    }
}
