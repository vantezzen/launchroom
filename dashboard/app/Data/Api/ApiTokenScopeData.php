<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Models\ApiToken;
use App\Models\Project;
use App\Models\Service;
use Spatie\LaravelData\Data;

/**
 * How far a scoped token reaches (docs/16 §1.4): one project, or one
 * service of it. `GET /me` answers it so a client (the MCP server, D112)
 * can offer only what the token can use.
 */
final class ApiTokenScopeData extends Data
{
    public function __construct(
        public string $projectId,
        public string $projectName,
        public ?string $serviceId,
        public ?string $serviceName,
    ) {}

    public static function fromToken(ApiToken $token): ?self
    {
        $service = $token->service;
        $project = $service instanceof Service ? $service->project : $token->project;

        if (! $project instanceof Project) {
            return null;
        }

        return new self(
            projectId: $project->public_id,
            projectName: $project->name,
            serviceId: $service?->public_id,
            serviceName: $service?->name,
        );
    }
}
