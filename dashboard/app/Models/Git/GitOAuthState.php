<?php

declare(strict_types=1);

namespace App\Models\Git;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending OAuth authorization (docs/19 §4.1): only the SHA-256 of the
 * `state` is stored, with the PKCE verifier (encrypted with APP_KEY) that
 * never leaves the server. Single use, team- and user-bound via its
 * connection and `user_id`, short-lived.
 *
 * @property int $id
 * @property string $state_hash
 * @property int $git_connection_id
 * @property int $user_id
 * @property string $code_verifier
 * @property CarbonImmutable $expires_at
 * @property-read GitConnection $gitConnection
 */
#[Fillable(['state_hash', 'git_connection_id', 'user_id', 'code_verifier', 'expires_at'])]
#[Hidden(['code_verifier'])]
#[Table(name: 'git_oauth_states')]
final class GitOAuthState extends Model
{
    /**
     * @return BelongsTo<GitConnection, $this>
     */
    public function gitConnection(): BelongsTo
    {
        return $this->belongsTo(GitConnection::class, 'git_connection_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code_verifier' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }
}
