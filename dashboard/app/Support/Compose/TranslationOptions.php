<?php

declare(strict_types=1);

namespace App\Support\Compose;

/**
 * How to translate a Compose file (docs/17 §2): the user's `.env` values,
 * pasted files for `env_file`, per-service database choices, whether bind
 * mounts of directories become volumes, and whether a repository is there
 * to build from. `template` turns on the `x-launchroom` extension (templates
 * only). `engines` are the managed engines' versions (newest first) and
 * defaults, from config.
 */
final readonly class TranslationOptions
{
    /**
     * @param  array<string, string>  $values
     * @param  array<string, string>  $files  path (relative, no `./`) => content
     * @param  array<string, 'managed'|'container'>  $databases  Compose service name => choice
     * @param  array<string, array{versions: list<string>, default: string}>  $engines  engine value => versions
     */
    public function __construct(
        public array $values = [],
        public array $files = [],
        public array $databases = [],
        public bool $convertBindMounts = true,
        public bool $repository = false,
        public bool $template = false,
        public array $engines = [],
        public ?string $name = null,
    ) {}
}
