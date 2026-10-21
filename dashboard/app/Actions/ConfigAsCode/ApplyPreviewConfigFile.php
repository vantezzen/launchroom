<?php

declare(strict_types=1);

namespace App\Actions\ConfigAsCode;

use App\Data\Audit\AuditActor;
use App\Data\Services\GitSource;
use App\Enums\ConfigAsCode\ConfigTrust;
use App\Models\Environment;
use App\Models\Previews\Preview;
use App\Services\ConfigAsCode\ConfigFileReader;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A pull request's launchroom.json, read at its head commit into the
 * preview environment (docs/18 §5) with the preview trust rules (forks:
 * stricter). Once per head commit (`previews.data.configSha`). The base
 * environment is never touched: the preview has its own copy.
 */
final readonly class ApplyPreviewConfigFile
{
    public function __construct(
        private ConfigFileReader $reader,
        private ApplyConfigFile $apply,
    ) {}

    /**
     * @return string|null why the preview fails (the file has problems), else null
     */
    public function handle(Preview $preview): ?string
    {
        // Callers may hold a copy from before the environment was created.
        $preview = Preview::query()->whereKey($preview->id)->first();

        if (! $preview instanceof Preview || $preview->environment_id === null || $preview->head_sha === '') {
            return null;
        }

        if (($preview->data['configSha'] ?? null) === $preview->head_sha) {
            $error = $preview->data['configError'] ?? null;

            return is_string($error) ? $error : null;
        }

        $environment = Environment::query()->whereKey($preview->environment_id)->with('project')->first();
        $repository = $environment === null ? null : $this->reader->repository($environment->project);

        if (! $environment instanceof Environment || ! $repository instanceof GitSource || $repository->repositoryId !== $preview->repository_id) {
            return null;
        }

        try {
            $content = $this->reader->read($environment->project, $repository, $preview->head_sha);
        } catch (RuntimeException $e) {
            Log::warning('launchroom.json of a pull request could not be read; the preview uses its base configuration.', ['preview' => $preview->public_id, 'error' => $e->getMessage()]);

            return null;
        }

        $outcome = $this->apply->handle(
            $environment,
            $content,
            $this->reader->settings($environment->project)->path,
            $repository,
            $preview->head_sha,
            $preview->is_fork ? ConfigTrust::Fork : ConfigTrust::Preview,
            AuditActor::system(),
        );

        $error = $outcome->invalid() ? $outcome->summary : null;
        $preview->forceFill(['data' => [...$preview->data, 'configSha' => $preview->head_sha, 'configError' => $error]])->save();

        return $error;
    }
}
