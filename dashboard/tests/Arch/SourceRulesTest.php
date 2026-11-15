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

use App\Support\Crypto\OpaqueToken;
use App\Support\Protocol\Grammar;
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

test('status transitions have one implementation', function (): void {
    $violations = [];

    foreach (phpFilesIn('app') as $path => $source) {
        if ($path !== 'app/Models/Concerns/HasStatusTransitions.php' && str_contains(codeWithoutComments($source), 'function transitionTo(')) {
            $violations[] = $path;
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

test('controllers flash messages through Toast only', function (): void {
    // The frontend reads only the Toast flash key; session keys like
    // `success` are never rendered (D82).
    $violations = [];

    foreach (phpFilesIn('app/Http') as $path => $source) {
        if (preg_match('/->\s*with\s*\(\s*[\'"](success|error|status|message)[\'"]/', codeWithoutComments($source)) === 1) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([]);
});

test('named status sets are used, not restated', function (): void {
    // D78: Server::notRevoked()/controlPlane(), DaemonTask::open().
    $forbidden = [
        '/[\'"]!=[\'"],\s*ServerStatus::Revoked/' => 'Server::notRevoked()',
        '/DaemonTaskStatus::Pending(->value)?,\s*DaemonTaskStatus::Leased(->value)?\s*\]/' => 'DaemonTask::open()',
        '/Server::query\(\)[^;]*?->where\(\s*[\'"]is_control_plane[\'"],\s*true\s*\)/s' => 'Server::controlPlane()',
        '/\[(\s*DeploymentStatus::(Active|Failed|Cancelled|Superseded|Ready)(->value)?\s*,?){4,5}\s*\]/' => 'DeploymentStatus::isFinished()/isSettled()',
        '/\[(\s*ServerMoveStatus::(Running|Ready)(->value)?\s*,?){2}\s*\]/' => 'ServerMoveStatus::underway()',
        '/\[(\s*ControlPlaneMoveStatus::(HandingOver|HandoverReady)(->value)?\s*,?){2}\s*\]/' => 'ControlPlaneMoveStatus::handingOver()',
        '/\[(\s*ImportRunStatus::(WaitingForSafetyBackup|Holding)(->value)?\s*,?){2}\s*\]/' => 'ImportRunStatus::waiting()',
    ];
    $violations = [];

    foreach (phpFilesIn('app') as $path => $source) {
        if (str_starts_with($path, 'app/Models/')) {
            continue;
        }

        foreach ($forbidden as $pattern => $owner) {
            if (preg_match($pattern, codeWithoutComments($source)) === 1) {
                $violations[] = "{$path}: use {$owner}";
            }
        }
    }

    expect($violations)->toBe([]);
});

test('generated grammars are referenced, not copied', function (): void {
    // R1/D76: Grammar is generated from launchroomd's validators; a copy drifts.
    $copies = [
        '[0-9a-f]{40}' => 'Grammar::GIT_SHA',
        '[A-Za-z0-9_.@+=,-]' => 'Grammar::REL_PATH',
        '[A-Za-z0-9._@+=,-]' => 'Grammar::REL_PATH',
        OpaqueToken::BODY => 'OpaqueToken',
    ];

    foreach (new ReflectionClass(Grammar::class)->getConstants() as $name => $body) {
        if (is_string($body) && str_ends_with($name, '_BODY')) {
            // Anchored: a grammar may start another, longer pattern (S3_ENDPOINT, git URLs).
            $copies['^'.$body.'$'] = 'Grammar::'.substr($name, 0, -strlen('_BODY'));
            $copies['^'.str_replace('/', '\\/', $body).'$'] = 'Grammar::'.substr($name, 0, -strlen('_BODY'));
        }
    }

    $owners = ['app/Support/Protocol/', 'app/Data/Protocol/', 'app/Support/Images/ImageReference.php', 'app/Support/Crypto/OpaqueToken.php'];
    $violations = [];

    foreach (phpFilesIn('app') as $path => $source) {
        foreach ($owners as $owner) {
            if (str_starts_with($path, $owner)) {
                continue 2;
            }
        }

        $code = codeWithoutComments($source);

        foreach ($copies as $literal => $owner) {
            if (str_contains($code, $literal)) {
                $violations[] = "{$path}: use {$owner}";
            }
        }
    }

    expect(array_values(array_unique($violations)))->toBe([]);
});

test('nested route parameters are checked through RouteScope', function (): void {
    // D78: RouteScope owns "this child belongs to that parent" for routes.
    $violations = [];

    foreach (phpFilesIn('app/Http/Controllers') as $path => $source) {
        if (preg_match('/abort_(unless|if)\(\s*\$\w+->(?!team_id\b)\w+_id\s*[!=]==/', codeWithoutComments($source)) === 1) {
            $violations[] = "{$path}: use RouteScope";
        }
    }

    expect($violations)->toBe([]);
});

test('placements are only created by the Placer', function (): void {
    $violations = [];

    foreach (phpFilesIn('app') as $path => $source) {
        if ($path !== 'app/Services/Deployments/Placer.php' && preg_match('/Placement::(query\(\)->)?create\(/', codeWithoutComments($source)) === 1) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([]);
});
