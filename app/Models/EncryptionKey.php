<?php

namespace App\Models;

use App\Services\GitHub;
use App\Traits\HasHashIds;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use phpseclib3\Crypt\RSA;

class EncryptionKey extends Model
{
    /** @use HasFactory<\Database\Factories\EncryptionKeyFactory> */
    use HasFactory;

    use HasHashIds;

    protected $hashPrefix = 'key_';

    protected $fillable = [
        'id',
        'team_id',
        'public_key',
        'private_key',
    ];

    protected $casts = [
        'private_key' => 'encrypted',
    ];

    protected $hidden = ['private_key'];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function storeInFilesystem()
    {
        $baseFolder = storage_path('app/private/encryption_keys/');
        if (! file_exists($baseFolder)) {
            mkdir($baseFolder, 0755, true);
        }

        $path = storage_path('app/private/encryption_keys/'.$this->id);
        if (! file_exists($path)) {
            file_put_contents($path, $this->private_key);
        }

        return $path;
    }

    public function addToGitHub()
    {
        $github = new GitHub($this->team);
        $github->addSshKey($this->public_key, 'Launchroom Deployer ('.$this->team->id.')');
    }

    public function deleteFromFilesystem()
    {
        $path = storage_path('app/private/encryption_keys/'.$this->id);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    public static function newForTeam(Team $team)
    {
        try {
            $key = RSA::createKey();

            $instance = self::create([
                'team_id' => $team->id,
                'public_key' => $key->getPublicKey()->toString('OpenSSH', ['comment' => "launchroom::{$team->id}"]),
                'private_key' => $key->toString('PKCS1'),
            ]);

            if ($team->github_token) {
                $instance->addToGitHub();
            }

            return $instance;
        } catch (\Throwable $e) {
            throw new \Exception('Failed to generate new key: '.$e->getMessage());
        }
    }
}
