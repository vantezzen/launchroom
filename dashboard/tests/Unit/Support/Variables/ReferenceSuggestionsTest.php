<?php

declare(strict_types=1);

use App\Data\Variables\ReferenceFixData;
use App\Support\Variables\ReferenceSuggestions;

function suggestions(): ReferenceSuggestions
{
    return new ReferenceSuggestions([
        'shared' => ['REGION'],
        'web' => ['PUBLIC_URL', 'DOMAIN', 'ANALYTICS_URL'],
        'api' => ['PUBLIC_URL', 'PRIVATE_URL', 'DOMAIN'],
        'postgres' => ['DATABASE_URL'],
    ]);
}

/**
 * @param  list<ReferenceFixData>  $fixes
 * @return array<string, list<string>>
 */
function fixMap(array $fixes): array
{
    return array_column(array_map(static fn (ReferenceFixData $fix): array => [$fix->expression, $fix->suggestions], $fixes), 1, 0);
}

test('a missing service suggests the same key on the services that have it', function (): void {
    expect(fixMap(suggestions()->fixes('web', 'ANALYTICS_URL', '${{analytics.PUBLIC_URL}}/script.js')))
        ->toBe(['${{analytics.PUBLIC_URL}}' => ['${{api.PUBLIC_URL}}', '${{web.PUBLIC_URL}}']]);
});

test('a typo in the key suggests the closest keys of that service first', function (): void {
    expect(fixMap(suggestions()->fixes('web', 'DB', '${{postgres.DATABSE_URL}}')))
        ->toBe(['${{postgres.DATABSE_URL}}' => ['${{postgres.DATABASE_URL}}']])
        ->and(fixMap(suggestions()->fixes('web', 'X', '${{api.PRIVATE_UR}}'))['${{api.PRIVATE_UR}}'][0])->toBe('${{api.PRIVATE_URL}}');
});

test('own-service references keep their short form and never point at the variable itself', function (): void {
    expect(fixMap(suggestions()->fixes('web', 'DOMAIN', '${{DOMAN}}')))
        ->toBe(['${{DOMAN}}' => ['${{api.DOMAIN}}']])
        ->and(fixMap(suggestions()->fixes('web', 'X', '${{DOMAN}}'))['${{DOMAN}}'][0])->toBe('${{DOMAIN}}');
});

test('valid references need no fix, and unrelated names get no suggestion', function (): void {
    expect(suggestions()->fixes('web', 'X', '${{api.DOMAIN}} ${{shared.REGION}}'))->toBe([])
        ->and(fixMap(suggestions()->fixes('web', 'X', '${{nope.COMPLETELY_DIFFERENT}}')))->toBe(['${{nope.COMPLETELY_DIFFERENT}}' => []]);
});

test('at most three suggestions, each broken expression once', function (): void {
    $many = new ReferenceSuggestions(['a' => ['KEY'], 'b' => ['KEY'], 'c' => ['KEY'], 'd' => ['KEY'], 'web' => []]);

    expect($many->fixes('web', 'X', '${{e.KEY}} ${{e.KEY}}'))->toHaveCount(1)
        ->and($many->fixes('web', 'X', '${{e.KEY}}')[0]->suggestions)->toHaveCount(ReferenceSuggestions::LIMIT);
});

test('a typo in the key of an existing service suggests only that service\'s keys', function (): void {
    $withConnection = new ReferenceSuggestions(['web' => ['DATABASE_URL'], 'postgres' => ['DATABASE_URL']]);

    expect(fixMap($withConnection->fixes('web', 'DB', '${{postgres.DATABSE_URL}}')))
        ->toBe(['${{postgres.DATABSE_URL}}' => ['${{postgres.DATABASE_URL}}']]);
});
