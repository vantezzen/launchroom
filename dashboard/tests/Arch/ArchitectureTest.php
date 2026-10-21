<?php

declare(strict_types=1);
use App\Support\Ingress\OvhSignature;

/*
|--------------------------------------------------------------------------
| Architecture rules (docs/10-codebase-conventions.md §2, docs/09-security.md §3)
|--------------------------------------------------------------------------
|
| Pest arch expectations work on symbol dependencies (classes and functions a
| file references), not on arbitrary syntax. Rules that need syntax (e.g.
| "controllers don't call ::find(") live in SourceRulesTest.php as small,
| explicit source scans.
|
*/

arch('PHP hygiene preset (no debug calls, no die/exit, ...)')
    ->preset()->php();

arch('security preset (no eval/exec/unserialize/weak randomness, ...)')
    ->preset()->security()
    // OVHcloud's API defines its request signature with SHA-1 (D47); the
    // one class that computes it is documented and allowed here.
    ->ignoring(['Tests', OvhSignature::class]);

arch('no debug functions')
    ->expect(['dd', 'dump', 'ddd', 'ray', 'var_dump', 'var_export', 'print_r', 'error_log', 'debug_zval_refcount'])
    ->not->toBeUsed()
    ->ignoring('Tests');

arch('no shell functions anywhere (docs/09 R1)')
    ->expect(['exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'pcntl_exec'])
    ->not->toBeUsed();

arch('no process execution in app code (docs/10 §2.2 rule 8: none in request paths)')
    ->expect('App')
    ->not->toUse([
        'Symfony\Component\Process\Process',
        'Illuminate\Support\Facades\Process',
        'Illuminate\Process\Factory',
    ])
    // The one exception: restic/pg_dump/pg_restore for control-plane backups
    // and restores (docs/14 §2), argv only, used from jobs and console only.
    ->ignoring('App\Services\Backups\Process');

arch('backup processes never run in a request (docs/10 §2.2 rule 8)')
    ->expect('App\Services\Backups\Process')
    ->toOnlyBeUsedIn([
        'App\Services\Backups',
        'App\Actions\Backups',
        'App\Jobs\Backups',
        'App\Console\Commands\Backups',
        'App\Providers\BackupsServiceProvider',
    ]);

arch('env() is only read in config/ (which is not namespaced, so not scanned)')
    ->expect(['env', 'getenv', 'putenv'])
    ->not->toBeUsed();

arch('app code declares strict types')
    ->expect(['App', 'Database'])
    ->toUseStrictTypes();

arch('actions are final with a single public handle() method')
    ->expect('App\Actions')
    ->classes()
    ->toBeFinal()
    ->toHaveMethod('handle')
    // Fortify dictates its own contracts (create()/reset()); see Fortify docs.
    ->ignoring('App\Actions\Fortify');

arch('actions expose nothing but handle()')
    ->expect('App\Actions')
    ->not->toHavePublicMethodsBesides(['__construct', 'handle'])
    ->ignoring('App\Actions\Fortify');

arch('actions never touch HTTP objects or the session')
    ->expect('App\Actions')
    ->not->toUse(['Illuminate\Http', 'Illuminate\Support\Facades\Request', 'Illuminate\Support\Facades\Session']);

arch('models do not depend on HTTP')
    ->expect('App\Models')
    ->not->toUse(['Illuminate\Http', 'Illuminate\Support\Facades\Request', 'Illuminate\Support\Facades\Http']);

arch('request()/session() helpers stay in the HTTP layer')
    ->expect(['request', 'session'])
    ->not->toBeUsedIn(['App\Actions', 'App\Models', 'App\Services', 'App\Support', 'App\Enums', 'App\Jobs']);

arch('models are final')
    ->expect('App\Models')
    ->classes()
    ->toBeFinal();

arch('controllers do not query through the DB facade or query builder')
    ->expect('App\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Database\Query\Builder', 'Illuminate\Database\Eloquent\Builder']);

arch('enums are backed enums')
    ->expect('App\Enums')
    ->enums()
    ->toBeStringBackedEnums();

arch('support utilities are framework-agnostic')
    ->expect('App\Support')
    ->not->toUse(['Illuminate\Http', 'Illuminate\Database', 'Illuminate\Support\Facades']);

arch('services never reach for the request or session')
    ->expect('App\Services')
    ->not->toUse(['Illuminate\Http\Request', 'Illuminate\Support\Facades\Request', 'Illuminate\Support\Facades\Session']);

arch('outbound HTTP to user-influenced URLs goes through SafeHttpClient')
    ->expect(['GuzzleHttp\Client', 'curl_init', 'curl_exec', 'file_get_contents', 'fopen'])
    ->toOnlyBeUsedIn([
        'App\Services\Http',
        // The Anthropic SDK's transport: a fixed, configured base URL
        // (api.anthropic.com), never user input (docs/20 §4).
        'App\Services\Dockerfiles\AnthropicTransport',
        // The dev stub model reads its answer from a local file (local/testing only).
        'App\Services\Dockerfiles\StubDockerfileModel',
        // Key files are local paths from config, never URLs.
        'App\Services\Crypto\KeyFile',
        'App\Services\Crypto\SigningKeyFile',
        'App\Services\Registry\RegistryTokenKeyFile',
        // Control-plane backup key bundle and the restore CLI: local key
        // files, recovery kit and passphrase files given by the operator.
        'App\Services\Backups\ControlPlaneKeyBundle',
        'App\Services\Backups\RestoredKeyFiles',
        'App\Console\Commands\Backups\RestoreControlPlaneCommand',
        // The registry (Zot) config file, a local path from config (docs/14 §9.11).
        'App\Services\Registry\ZotConfigFile',
    ]);
