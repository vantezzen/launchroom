<?php

declare(strict_types=1);

use App\Http\Api\OpenApi\OpenApiDocument;
use App\Http\Api\OpenApi\OpenApiGenerator;

test('the committed OpenAPI document matches the routes and Data objects (drift)', function (): void {
    $generated = OpenApiDocument::encode(resolve(OpenApiGenerator::class)->generate());

    expect($generated)->toBe(OpenApiDocument::committed(), 'resources/api/openapi.json is out of date: run `make gen-openapi`.');
});

test('the document is OpenAPI 3.1 with every operation documented', function (): void {
    $document = OpenApiDocument::decoded();
    $ids = [];

    expect($document['openapi'])->toBe('3.1.0');

    foreach ($document['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            expect($operation)->toHaveKeys(['operationId', 'summary', 'tags', 'responses']);
            $ids[] = $operation['operationId'];

            if (! in_array($path, ['/openapi.json', '/cli/sessions', '/cli/sessions/token', '/me'], true)) {
                expect(array_key_exists('x-launchroom-ability', $operation))->toBeTrue("{$method} {$path}");
            }
        }
    }

    expect($ids)->toBe(array_values(array_unique($ids)));
});

test('the document is served publicly and never mentions secrets values', function (): void {
    $this->get('/api/v1/openapi.json')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('info.title', 'launchroom API');
});

test('the check command passes on the committed document', function (): void {
    $this->artisan('launchroom:api:openapi', ['--check' => true])->assertSuccessful();
});
