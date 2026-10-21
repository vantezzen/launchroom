<?php

declare(strict_types=1);

namespace App\Console\Commands\Templates;

use App\Services\Templates\TemplateCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * `make lint-templates` (docs/17 §3.3): every shipped template parses,
 * pins its images by digest and translates through the Compose allow-list
 * without a refused or ignored line.
 */
#[Signature('launchroom:templates:lint')]
#[Description('Validate the shipped templates (schema, digest-pinned images, the Compose allow-list).')]
final class LintTemplatesCommand extends Command
{
    public function handle(TemplateCatalog $catalog): int
    {
        $failed = 0;
        $results = $catalog->lint();

        if ($results === []) {
            $this->components->error('No templates found in '.TemplateCatalog::directory().'.');

            return self::FAILURE;
        }

        foreach ($results as $id => $problems) {
            if ($problems === []) {
                $this->components->twoColumnDetail($id, '<fg=green>ok</>');

                continue;
            }

            $failed++;
            $this->components->twoColumnDetail($id, '<fg=red>'.count($problems).' '.(count($problems) === 1 ? 'problem' : 'problems').'</>');

            foreach ($problems as $problem) {
                $this->line('    • '.$problem);
            }
        }

        if ($failed > 0) {
            $this->components->error("{$failed} of ".count($results).' templates have problems.');

            return self::FAILURE;
        }

        $this->components->info(count($results).' templates are valid.');

        return self::SUCCESS;
    }
}
