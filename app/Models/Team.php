<?php

namespace App\Models;

use App\Traits\HasHashIds;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

class Team extends Model
{
    /** @use HasFactory<\Database\Factories\TeamFactory> */
    use HasFactory;

    use HasHashIds;

    use HasRelationships;

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

    public function deployments()
    {
        return $this->hasManyDeep(Deployment::class, [Project::class, ProjectEnvironment::class]);
    }
    public function services()
    {
        return $this->hasManyDeep(Service::class, [Project::class, ProjectEnvironment::class]);
    }
    public function processingUsages()
    {
        return $this->hasManyDeep(ProcessingUsage::class, [Project::class, ProjectEnvironment::class]);
    }
    public function environments()
    {
        return $this->hasManyDeep(ProjectEnvironment::class, [Project::class]);
    }
}
