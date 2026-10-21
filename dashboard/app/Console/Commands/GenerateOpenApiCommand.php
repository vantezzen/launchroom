<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Api\OpenApi\OpenApiDocument;
use App\Http\Api\OpenApi\OpenApiGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Writes (or checks) the committed OpenAPI document (docs/16 §2.4).
 */
#[Signature('launchroom:api:openapi {--check : Fail when the committed document is out of date}')]
#[Description('Generate resources/api/openapi.json from the /api/v1 routes and Data objects')]
final class GenerateOpenApiCommand extends Command
{
    public function handle(OpenApiGenerator $generator): int
    {
        $document = OpenApiDocument::encode($generator->generate());

        if ($this->option('check')) {
            if (! File::isFile(OpenApiDocument::path()) || File::get(OpenApiDocument::path()) !== $document) {
                $this->components->error('resources/api/openapi.json is out of date. Run php artisan launchroom:api:openapi.');

                return self::FAILURE;
            }

            $this->components->info('The OpenAPI document is up to date.');

            return self::SUCCESS;
        }

        File::put(OpenApiDocument::path(), $document);
        $this->components->info('Wrote resources/api/openapi.json.');

        return self::SUCCESS;
    }
}
