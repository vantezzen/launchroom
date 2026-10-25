<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Data\Services\ChangeImageData;
use App\Data\Services\ImageSource;
use App\Enums\AuditAction;
use App\Enums\ServiceKind;
use App\Exceptions\DomainRuleViolation;
use App\Models\Service;
use App\Support\Compose\ComposeTranslator;
use App\Support\Images\ImageReference;
use Illuminate\Support\Facades\DB;

/**
 * Points an image service at another image (docs/04 §1). The source is an
 * identity field shared by every environment of the project (docs/06 §4),
 * so the change shows as a staged change in each of them and runs with
 * each environment's next deploy; Discard puts the old image back. Tags are
 * pinned to a digest when the deploy starts (D24), so the reference may be
 * a tag or `@sha256:` digest, from a registry the mirror serves.
 *
 * Switching between an image and a repository is not a source change: the
 * kind decides the whole pipeline (build settings, plan previews, workers),
 * so that is a new service.
 */
final readonly class ChangeServiceImage
{
    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    public function handle(Service $service, ChangeImageData $data, AuditActor $actor): Service
    {
        $image = trim($data->image);
        $reference = ImageReference::tryParse($image);

        if (! $reference instanceof ImageReference) {
            throw DomainRuleViolation::on('image', 'The image reference is not valid (e.g. ghcr.io/org/app:1.2 or postgres@sha256:…).');
        }

        if (! in_array($reference->registry, ComposeTranslator::REGISTRIES, true)) {
            throw DomainRuleViolation::on('image', "Images from {$reference->registry} can’t be pulled yet: launchroom mirrors Docker Hub and GitHub Container Registry.");
        }

        return DB::transaction(function () use ($service, $image, $actor): Service {
            $service = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
            $source = $service->source;

            if ($service->kind !== ServiceKind::Image || ! $source instanceof ImageSource) {
                throw DomainRuleViolation::on('image', 'Only image services run a published image. To run an image instead of a build, add it as a new service.');
            }

            $before = $source->reference;

            if ($before === $image) {
                return $service;
            }

            $service->forceFill(['source' => new ImageSource($image)])->save();

            $this->audit->handle(new AuditEntryData(
                action: AuditAction::ServiceImageChanged,
                actor: $actor,
                teamId: $service->team_id,
                subjectType: 'service',
                subjectId: $service->public_id,
                meta: ['from' => $before, 'to' => $image],
            ));

            return $service;
        });
    }
}
