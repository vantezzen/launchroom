<?php

namespace App\Models;

use App\Traits\HasHashIds;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    /** @use HasFactory<\Database\Factories\TeamFactory> */
    use HasFactory;

    use HasHashIds;

    protected $fillable = ['name', 'slug', 'github_token', 'github_username'];

    protected $hidden = ['github_token'];

    protected $casts = [
        'github_token' => 'encrypted',
    ];

    protected $hashPrefix = 'team_';

    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function encryptionKey()
    {
        return $this->hasOne(EncryptionKey::class);
    }
}
