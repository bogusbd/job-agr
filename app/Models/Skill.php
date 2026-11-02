<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A Freelancer.com "job" (skill) that projects are tagged with. The primary key is the remote job ID.
 */
final class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use HasFactory;

    public $incrementing = false;

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }
}
