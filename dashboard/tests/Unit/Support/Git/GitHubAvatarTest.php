<?php

declare(strict_types=1);

use App\Support\Git\GitHubAvatar;

test('avatar URLs are rebuilt on avatars.githubusercontent.com', function (?string $url, ?string $login, ?string $expected): void {
    expect(GitHubAvatar::url($url, $login))->toBe($expected);
})->with([
    'GitHub id URL' => ['https://avatars.githubusercontent.com/u/583231?v=4&s=460', null, 'https://avatars.githubusercontent.com/u/583231?v=4'],
    'login URL' => ['https://avatars.githubusercontent.com/octocat', null, 'https://avatars.githubusercontent.com/octocat?v=4'],
    'from the login' => [null, 'mara-dev', 'https://avatars.githubusercontent.com/mara-dev?v=4'],
    'other host falls back to the login' => ['https://evil.test/u/1', 'mara', 'https://avatars.githubusercontent.com/mara?v=4'],
    'look-alike host' => ['https://avatars.githubusercontent.com.evil.test/u/1', null, null],
    'plain http' => ['http://avatars.githubusercontent.com/u/1', null, null],
    'port' => ['https://avatars.githubusercontent.com:8443/u/1', null, null],
    'credentials' => ['https://user:pw@avatars.githubusercontent.com/u/1', null, null],
    'path traversal' => ['https://avatars.githubusercontent.com/../u/1', null, null],
    'invalid login' => [null, 'mara/../x', null],
    'bot' => [null, 'dependabot[bot]', null],
    'nothing' => [null, null, null],
]);
