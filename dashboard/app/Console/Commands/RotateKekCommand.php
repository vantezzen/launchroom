<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Crypto\RotateKek;
use App\Services\Crypto\Envelope;
use App\Services\Crypto\Exceptions\InvalidKeyFile;
use App\Services\Crypto\KeyFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

#[Signature('launchroom:keys:rotate-kek {--new-key= : Path of the new KEK file (created when missing; default: <KEK path>.next)}')]
#[Description('Re-wrap every data key with a new key-encryption key (docs/09 R13); then replace the KEK file and restart')]
final class RotateKekCommand extends Command
{
    public function handle(RotateKek $rotate, Envelope $current): int
    {
        $currentPath = config()->string('launchroom.kek_path');
        $nextPath = $this->option('new-key');
        $nextPath = is_string($nextPath) && $nextPath !== '' ? $nextPath : $currentPath.'.next';

        try {
            $next = new Envelope(file_exists($nextPath) ? KeyFile::read($nextPath) : KeyFile::create($nextPath));
            $counts = $rotate->handle($current, $next);
        } catch (InvalidKeyFile|RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        Log::notice('KEK rotated.', ['from' => $current->kekId(), 'to' => $next->kekId(), ...$counts]);

        $this->components->info("Re-wrapped {$counts['rewrapped']} data keys with KEK {$next->kekId()} ({$counts['skipped']} already were).");
        $this->components->warn('Until the next step, the running dashboard can no longer unwrap data keys:');
        $this->components->bulletList([
            "replace [{$currentPath}] with [{$nextPath}] (keep the old file offline until a new control-plane backup exists),",
            'restart the dashboard, horizon, reverb and scheduler containers,',
            'run a control-plane backup: older backups need the old KEK.',
        ]);

        return self::SUCCESS;
    }
}
