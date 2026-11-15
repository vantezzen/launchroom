<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Vocabulary\EnumVocabulary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('launchroom:vocabulary {--check : Fail when the generated file is out of date}')]
#[Description('Generate resources/js/types/vocabulary.generated.ts from the enums\' label(), tone() and shared facts')]
final class GenerateVocabularyCommand extends Command
{
    public function handle(EnumVocabulary $vocabulary): int
    {
        $contents = $vocabulary->render();

        if ($this->option('check')) {
            if (! File::isFile(EnumVocabulary::path()) || File::get(EnumVocabulary::path()) !== $contents) {
                $this->components->error('resources/js/types/vocabulary.generated.ts is out of date. Run make gen-types.');

                return self::FAILURE;
            }

            $this->components->info('The vocabulary is up to date.');

            return self::SUCCESS;
        }

        File::put(EnumVocabulary::path(), $contents);
        $this->components->info('Wrote resources/js/types/vocabulary.generated.ts.');

        return self::SUCCESS;
    }
}
