<?php

namespace App\Models;

use App\Traits\HasHashIds;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

class ProcessingUsage extends Model
{
    /** @use HasFactory<\Database\Factories\ProcessingUsageFactory> */
    use HasFactory;

    use HasHashIds;
    use HasRelationships;

    protected $hashPrefix = 'usg_';

    protected $fillable = [
        'cpu',
        'mem_usage',
        'mem_limit',
        'mem_percent',
        'net_in',
        'net_out',
        'block_in',
        'block_out',
    ];

    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }

    public function team()
    {
        return $this->hasOneDeep(Team::class, [Environment::class, Project::class]);
    }

    public function project()
    {
        return $this->hasOneDeep(Project::class, [Environment::class]);
    }
}
