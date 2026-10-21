<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Source-level rules Pest arch expectations can't express
|--------------------------------------------------------------------------
|
| These scan PHP tokens (comments and strings are ignored where it matters).
| They are deliberately simple; they catch the common forms, not every
| possible obfuscation. Code review and PHPStan cover the rest.
|
*/

use Symfony\Component\Finder\Finder;

// Arch tests run without booting Laravel, so no base_path().
const PROJECT_ROOT = __DIR__.'/../..';

/**
 * @return array<string, string> relative path => contents
 */
function phpFilesIn(string $directory): array
{
    $files = [];

    foreach (Finder::create()->files()->name('*.php')->in(PROJECT_ROOT.'/'.$directory) as $file) {
        $files[$directory.'/'.$file->getRelativePathname()] = $file->getContents();
    }

    return $files;
}

/**
 * PHP source without comments, so documentation can mention forbidden calls.
 */
function codeWithoutComments(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

test('controllers never look up models by raw id (docs/09 R7)', function (): void {
    // Lookups must go through team-scoped relations and route model binding.
    $forbidden = [
        '/::\s*find(OrFail|Many|Or)?\s*\(/i' => 'Model::find*()',
        '/->\s*find(OrFail|Many|Or)?\s*\(/i' => '->find*()',
        '/where\s*\(\s*[\'"](\w+\.)?id[\'"]/i' => "where('id', ...)",
        '/whereKey\s*\(/i' => 'whereKey()',
    ];

    $violations = [];

    foreach (phpFilesIn('app/Http/Controllers') as $path => $source) {
        $code = codeWithoutComments($source);

        foreach ($forbidden as $pattern => $label) {
            if (preg_match($pattern, $code) === 1) {
                $violations[] = "{$path}: {$label}";
            }
        }
    }

    expect($violations)->toBe([]);
});

test('no global helper files are autoloaded', function (): void {
    /** @var array{autoload?: array{files?: list<string>}, autoload-dev?: array{files?: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(PROJECT_ROOT.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['autoload']['files'] ?? [])->toBe([])
        ->and($composer['autoload-dev']['files'] ?? [])->toBe([]);
});

test('app/ contains only class-like declarations, no global functions', function (): void {
    $violations = [];

    foreach (phpFilesIn('app') as $path => $source) {
        $tokens = token_get_all($source);
        $depth = 0;
        $hasClassLike = false;

        foreach ($tokens as $token) {
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            } elseif (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $hasClassLike = true;
            } elseif (is_array($token) && $token[0] === T_FUNCTION && $depth === 0) {
                $violations[] = "{$path}: top-level function";
            }
        }

        if (! $hasClassLike) {
            $violations[] = "{$path}: no class, interface, trait or enum";
        }
    }

    expect($violations)->toBe([]);
});

test('status columns are never mass-assigned outside transition methods', function (): void {
    // docs/10 §2.2 rule 3: statuses change only through the model's transition
    // method. Approximation: no `->update([... 'status' => ...])` or
    // `'status' =>` inside create/fill/update calls in app/ outside Models.
    $violations = [];

    foreach (phpFilesIn('app') as $path => $source) {
        if (str_starts_with($path, 'app/Models/')) {
            continue;
        }

        $code = codeWithoutComments($source);

        if (preg_match('/->\s*(update|fill|forceFill|create|updateOrCreate|firstOrCreate)\s*\(\s*\[[^\]]*[\'"]status[\'"]\s*=>/s', $code) === 1) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([]);
});
