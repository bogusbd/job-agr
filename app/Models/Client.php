<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Freelancer.com user who owns (posted) projects. The primary key is the remote user ID.
 */
final class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    public $incrementing = false;

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'is_payment_verified' => 'boolean',
            'is_email_verified' => 'boolean',
            'is_deposit_made' => 'boolean',
            'is_phone_verified' => 'boolean',
            'is_identity_verified' => 'boolean',
            'rating' => 'decimal:2',
            'raw' => 'array',
            'fetched_at' => 'datetime',
        ];
    }
}
