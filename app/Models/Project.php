<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectType;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A project (job offer) imported from Freelancer.com. The primary key is the remote project ID.
 */
final class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    public $incrementing = false;

    /**
     * The main skill (category) of the project, taken from the first segment of its URL.
     *
     * @return BelongsTo<Skill, $this>
     */
    public function primarySkill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'primary_skill_id');
    }

    /**
     * @return BelongsToMany<Skill, $this>
     */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class);
    }

    public function url(): string
    {
        return 'https://www.freelancer.com/projects/'.($this->seo_url ?? $this->id);
    }

    public function primarySkillLabel(): ?string
    {
        if ($this->primary_skill_slug === null) {
            return null;
        }

        return $this->primarySkill->name ?? Str::headline($this->primary_skill_slug);
    }

    /**
     * A client without any reviews or completed projects on Freelancer.com.
     */
    public function hasNewClient(): bool
    {
        return $this->client_reviews_count === 0 && $this->client_completed_projects_count === 0;
    }

    /**
     * The client's country as a flag emoji, built from its two-letter ISO code (e.g. "us" → 🇺🇸).
     */
    public function clientCountryFlag(): ?string
    {
        $code = mb_strtoupper((string) $this->client_country_code);

        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            return null;
        }

        return mb_chr(0x1F1E6 + ord($code[0]) - ord('A')).mb_chr(0x1F1E6 + ord($code[1]) - ord('A'));
    }

    /**
     * Whether Freelancer.com specifies an explicit work location for the project.
     */
    public function hasLocation(): bool
    {
        return $this->location_country !== null || $this->location_city !== null;
    }

    /**
     * Human readable work location, e.g. "Ama, Aichi 490-1105, Japan".
     */
    public function locationLabel(): ?string
    {
        $region = mb_trim(collect([$this->location_region, $this->location_postal_code])->filter()->implode(' '));

        return collect([$this->location_city, $region, $this->location_country])->filter()->implode(', ') ?: null;
    }

    public function locationMapUrl(): ?string
    {
        if ($this->location_latitude === null || $this->location_longitude === null) {
            return null;
        }

        return "https://www.google.com/maps/search/?api=1&query={$this->location_latitude},{$this->location_longitude}";
    }

    public function markAsViewed(): void
    {
        if ($this->is_viewed) {
            return;
        }

        $this->update(['is_viewed' => true, 'viewed_at' => now()]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProjectType::class,
            'exchange_rate' => 'decimal:8',
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'budget_min_usd' => 'decimal:2',
            'budget_max_usd' => 'decimal:2',
            'bid_avg' => 'decimal:2',
            'bid_avg_usd' => 'decimal:2',
            'is_featured' => 'boolean',
            'is_urgent' => 'boolean',
            'is_sealed' => 'boolean',
            'is_nda' => 'boolean',
            'is_fulltime' => 'boolean',
            'is_local' => 'boolean',
            'location_latitude' => 'float',
            'location_longitude' => 'float',
            'is_hireme' => 'boolean',
            'is_qualified' => 'boolean',
            'is_premium' => 'boolean',
            'is_recruiter' => 'boolean',
            'is_enterprise' => 'boolean',
            'client_registered_at' => 'datetime',
            'client_rating' => 'decimal:2',
            'client_completion_rate' => 'decimal:2',
            'client_earnings_score' => 'decimal:4',
            'client_payment_verified' => 'boolean',
            'client_deposit_made' => 'boolean',
            'client_email_verified' => 'boolean',
            'client_phone_verified' => 'boolean',
            'client_identity_verified' => 'boolean',
            'client_profile_complete' => 'boolean',
            'client_freelancer_verified' => 'boolean',
            'is_active' => 'boolean',
            'is_viewed' => 'boolean',
            'is_garbage' => 'boolean',
            'submitted_at' => 'datetime',
            'remote_updated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'viewed_at' => 'datetime',
            'raw' => 'array',
        ];
    }
}
