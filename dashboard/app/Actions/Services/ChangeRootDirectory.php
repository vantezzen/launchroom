<?php

declare(strict_types=1);

namespace App\Actions\Services;

use App\Data\Services\GitSource;
use App\Enums\ServiceKind;
use App\Events\CanvasChanged;
use App\Exceptions\DomainRuleViolation;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Points an app at another folder of its repository (monorepos, docs/04
 * §1). Like the image, the source is shared by every environment (docs/06
 * §4): the change is staged in each of them.
 */
final readonly class ChangeRootDirectory
{
    public function handle(Service $service, string $rootDirectory): Service
    {
        return DB::transaction(function () use ($service, $rootDirectory): Service {
            $service = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
            $source = $service->source;

            if ($service->kind !== ServiceKind::App || ! $source instanceof GitSource) {
                throw DomainRuleViolation::on('rootDirectory', 'Only apps built from a repository have a root directory.');
            }

            if ($source->rootDirectory === $rootDirectory) {
                return $service;
            }

            $service->forceFill(['source' => GitSource::from([...$source->toArray(), 'rootDirectory' => $rootDirectory])])->save();
            CanvasChanged::forService($service);

            return $service;
        });
    }
}
