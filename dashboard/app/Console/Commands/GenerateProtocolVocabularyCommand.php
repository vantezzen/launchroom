<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Vocabulary\ProtocolVocabulary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('launchroom:protocol-vocabulary {--check : Fail when a generated file is out of date}')]
#[Description('Generate App\Support\Protocol\{Grammar,Limits} and resources/js/types/grammar.generated.ts from protocol/schema/v1/vocabulary.json')]
final class GenerateProtocolVocabularyCommand extends Command
{
    public function handle(ProtocolVocabulary $vocabulary): int
    {
        $stale = [];

        foreach ($vocabulary->render() as $relative => $contents) {
            $path = base_path($relative);

            if ($this->option('check')) {
                if (! File::isFile($path) || File::get($path) !== $contents) {
                    $stale[] = $relative;
                }

                continue;
            }

            File::put($path, $contents);
            $this->components->info("Wrote {$relative}.");
        }

        if ($stale !== []) {
            $this->components->error(implode(', ', $stale).' out of date. Run make gen-types.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
