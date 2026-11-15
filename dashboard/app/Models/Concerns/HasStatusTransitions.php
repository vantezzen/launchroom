<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\Contracts\HasTransitionTable;
use Illuminate\Database\Eloquent\Model;

/**
 * The single transition method of a model whose `status` is a
 * {@see HasTransitionTable} enum (docs/10 §2.2 rule 3): assert the edge, set
 * the status, let the model record what goes with it, save, then emit.
 *
 * `$note` is the error or reason that goes with the new status. It is capped
 * at NOTE_LIMIT, the width of every note column, and the model decides in
 * `recordTransition()` which column it lands in and when.
 *
 * @template TStatus of HasTransitionTable
 *
 * @phpstan-require-extends Model
 */
trait HasStatusTransitions
{
    public const int NOTE_LIMIT = 2048;

    /**
     * @param  TStatus  $to
     */
    public function transitionTo(HasTransitionTable $to, ?string $note = null): void
    {
        /** @var TStatus $from */
        $from = $this->status;
        $from->assertCanTransitionTo($to);

        $this->status = $to;
        $this->recordTransition($from, $to, $note === null ? null : mb_substr($note, 0, self::NOTE_LIMIT));
        $this->save();

        $this->transitioned($from, $to);
    }

    /**
     * Timestamps, notes and resets that change with the status, before the save.
     *
     * @param  TStatus  $from
     * @param  TStatus  $to
     */
    protected function recordTransition(HasTransitionTable $from, HasTransitionTable $to, ?string $note): void {}

    /**
     * Events after the save.
     *
     * @param  TStatus  $from
     * @param  TStatus  $to
     */
    protected function transitioned(HasTransitionTable $from, HasTransitionTable $to): void {}
}
