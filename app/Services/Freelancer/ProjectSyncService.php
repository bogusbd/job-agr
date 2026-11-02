<?php

declare(strict_types=1);

namespace App\Services\Freelancer;

use App\Models\Project;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Imports Freelancer.com projects and their skills into the local database.
 * Freelancer.com does not expose who owns a project, so the owner's anonymous profile (`owner_info`) is stored on the project.
 *
 * Local-only columns (`is_viewed`, `viewed_at`, `is_garbage`) are never touched by the sync.
 */
final readonly class ProjectSyncService
{
    public function __construct(private FreelancerApiService $api) {}

    /**
     * The main skill slug is the first segment of the project URL, e.g. `engineering-drawing/Engineer-Stamp`.
     */
    public static function primarySkillSlug(?string $seoUrl): ?string
    {
        if ($seoUrl === null || ! str_contains($seoUrl, '/')) {
            return null;
        }

        return self::normalizeSlug(explode('/', $seoUrl, 2)[0]);
    }

    /**
     * Import every page of the active projects search for the given parameters.
     *
     * @param  array<string, mixed>  $parameters
     * @param  (Closure(int $page, int $imported, int $total): void)|null  $onPage
     * @return array{imported: int, total: int, complete: bool}
     */
    public function syncActiveProjects(array $parameters = [], ?int $maxPages = null, ?Closure $onPage = null): array
    {
        $imported = 0;
        $total = 0;

        foreach ($this->api->paginateActiveProjects($parameters, $maxPages) as $page => $response) {
            $total = $response['total_count'];
            $imported += $this->storeProjects($response['projects']);

            if ($onPage instanceof Closure) {
                $onPage($page + 1, $imported, $total);
            }
        }

        return ['imported' => $imported, 'total' => $total, 'complete' => $imported >= $total];
    }

    /**
     * Mark active projects that were not seen since the given moment as inactive (closed / awarded / deleted).
     */
    public function deactivateProjectsNotSeenSince(CarbonImmutable $since): int
    {
        return Project::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $since))
            ->update(['is_active' => false]);
    }

    /**
     * @param  list<array<string, mixed>>  $projects
     */
    public function storeProjects(array $projects): int
    {
        if ($projects === []) {
            return 0;
        }

        DB::transaction(function () use ($projects): void {
            $this->storeSkills($projects);

            Project::query()->upsert(
                array_map($this->mapProject(...), $projects),
                ['id'],
                array_keys(Arr::except($this->mapProject($projects[0]), ['id', 'created_at'])),
            );

            $this->syncProjectSkills($projects);
            $this->resolvePrimarySkills($projects);
        });

        return count($projects);
    }

    /**
     * Skills use underscores (`event_photography`) while project URLs use dashes (`event-photography`).
     */
    private static function normalizeSlug(string $slug): string
    {
        return mb_strtolower(str_replace('_', '-', $slug));
    }

    /**
     * @param  list<array<string, mixed>>  $projects
     */
    private function storeSkills(array $projects): void
    {
        $now = now();

        $rows = collect($projects)
            ->flatMap(fn (array $project): array => $project['jobs'] ?? [])
            ->filter(fn (mixed $job): bool => is_array($job) && isset($job['id'], $job['name']))
            ->unique('id')
            ->map(fn (array $job): array => [
                'id' => $job['id'],
                'name' => $job['name'],
                'seo_url' => $job['seo_url'] ?? null,
                'category_id' => $job['category']['id'] ?? null,
                'category_name' => $job['category']['name'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            DB::table('skills')->upsert($rows, ['id'], ['name', 'seo_url', 'category_id', 'category_name', 'updated_at']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $projects
     */
    private function syncProjectSkills(array $projects): void
    {
        $projectIds = array_column($projects, 'id');

        $rows = collect($projects)
            ->flatMap(fn (array $project): array => collect($project['jobs'] ?? [])
                ->filter(fn (mixed $job): bool => is_array($job) && isset($job['id']))
                ->map(fn (array $job): array => ['project_id' => $project['id'], 'skill_id' => $job['id']])
                ->unique('skill_id')
                ->all())
            ->values()
            ->all();

        DB::table('project_skill')->whereIn('project_id', $projectIds)->delete();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('project_skill')->insert($chunk);
        }
    }

    /**
     * Link each project to the skill whose slug matches the first segment of the project URL.
     * The slug is matched against all known skills, not only the ones attached to the project.
     *
     * @param  list<array<string, mixed>>  $projects
     */
    private function resolvePrimarySkills(array $projects): void
    {
        $slugsByProject = collect($projects)
            ->mapWithKeys(fn (array $project): array => [$project['id'] => self::primarySkillSlug($project['seo_url'] ?? null)])
            ->filter();

        if ($slugsByProject->isEmpty()) {
            return;
        }

        $skillIdsBySlug = DB::table('skills')
            ->whereIn('seo_url', $slugsByProject->flatMap(fn (string $slug): array => [$slug, str_replace('-', '_', $slug)])->unique()->values())
            ->pluck('id', 'seo_url')
            ->mapWithKeys(fn (int $id, string $seoUrl): array => [self::normalizeSlug($seoUrl) => $id]);

        $slugsByProject
            ->map(fn (string $slug): ?int => $skillIdsBySlug->get($slug))
            ->filter()
            ->groupBy(fn (int $skillId): int => $skillId, preserveKeys: true)
            ->each(fn ($projectsOfSkill, int $skillId) => Project::query()
                ->whereKey($projectsOfSkill->keys()->all())
                ->update(['primary_skill_id' => $skillId]));
    }

    /**
     * @param  array<string, mixed>  $project
     * @return array<string, mixed>
     */
    private function mapProject(array $project): array
    {
        $exchangeRate = isset($project['currency']['exchange_rate']) ? (float) $project['currency']['exchange_rate'] : null;
        $upgrades = $project['upgrades'] ?? [];
        $now = now();

        return [
            'id' => $project['id'],
            'title' => $project['title'] ?? '',
            'seo_url' => $project['seo_url'] ?? null,
            'primary_skill_slug' => self::primarySkillSlug($project['seo_url'] ?? null),
            'primary_skill_id' => null,
            'preview_description' => $project['preview_description'] ?? null,
            'description' => $project['description'] ?? null,
            'type' => $project['type'] ?? 'fixed',
            'status' => $project['status'] ?? null,
            'frontend_status' => $project['frontend_project_status'] ?? null,
            'language' => $project['language'] ?? null,
            'currency_code' => $project['currency']['code'] ?? null,
            'currency_sign' => $project['currency']['sign'] ?? null,
            'exchange_rate' => $exchangeRate,
            'budget_min' => $project['budget']['minimum'] ?? null,
            'budget_max' => $project['budget']['maximum'] ?? null,
            'budget_min_usd' => $this->toUsd($project['budget']['minimum'] ?? null, $exchangeRate),
            'budget_max_usd' => $this->toUsd($project['budget']['maximum'] ?? null, $exchangeRate),
            'hourly_hours' => $project['hourly_project_info']['commitment']['hours'] ?? null,
            'hourly_interval' => $project['hourly_project_info']['commitment']['interval'] ?? null,
            'bid_count' => (int) ($project['bid_stats']['bid_count'] ?? 0),
            'bid_avg' => $project['bid_stats']['bid_avg'] ?? null,
            'bid_avg_usd' => $this->toUsd($project['bid_stats']['bid_avg'] ?? null, $exchangeRate),
            'bid_period' => $project['bidperiod'] ?? null,
            'is_featured' => (bool) ($upgrades['featured'] ?? $project['featured'] ?? false),
            'is_urgent' => (bool) ($upgrades['urgent'] ?? $project['urgent'] ?? false),
            'is_sealed' => (bool) ($upgrades['sealed'] ?? false),
            'is_nda' => (bool) ($upgrades['NDA'] ?? false),
            'is_fulltime' => (bool) ($upgrades['fulltime'] ?? false),
            'is_local' => (bool) ($project['local'] ?? false),
            'is_hireme' => (bool) ($project['hireme'] ?? false),
            'is_qualified' => (bool) ($upgrades['qualified'] ?? false),
            'is_premium' => (bool) ($upgrades['premium'] ?? false),
            'is_recruiter' => (bool) ($upgrades['recruiter'] ?? false),
            'is_enterprise' => (bool) ($upgrades['enterprise'] ?? false) || ($project['enterprise_ids'] ?? []) !== [],
            'country_code' => $project['location']['country']['code'] ?? null,
            ...$this->mapOwner($project['owner_info'] ?? []),
            'submitted_at' => $this->timestamp($project['time_submitted'] ?? $project['submitdate'] ?? null),
            'remote_updated_at' => $this->timestamp($project['time_updated'] ?? null),
            'raw' => json_encode($project, JSON_UNESCAPED_UNICODE),
            'is_active' => ($project['status'] ?? 'active') === 'active',
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Map the anonymous owner profile returned with `owner_info=true`.
     * Review and completion counters are omitted by the API for clients without any history.
     *
     * @param  array<string, mixed>  $owner
     * @return array<string, mixed>
     */
    private function mapOwner(array $owner): array
    {
        $history = $owner['reputation']['entire_history'] ?? [];
        $status = $owner['status'] ?? [];
        $completionRate = $history['completion_rate'] ?? null;

        return [
            'client_country_code' => $owner['country']['code'] ?? null,
            'client_country_name' => $owner['country']['name'] ?? null,
            'client_city' => filled($owner['city'] ?? null) ? $owner['city'] : null,
            'client_registered_at' => $this->timestamp($owner['registration_date'] ?? null),
            'client_rating' => isset($history['overall']) ? round((float) $history['overall'], 2) : null,
            'client_reviews_count' => (int) ($history['reviews'] ?? 0),
            'client_completed_projects_count' => (int) ($history['complete'] ?? 0),
            'client_completion_rate' => $completionRate !== null ? round($completionRate * 100, 2) : null,
            'client_earnings_score' => $owner['reputation']['earnings_score'] ?? null,
            'client_payment_verified' => (bool) ($status['payment_verified'] ?? false),
            'client_deposit_made' => (bool) ($status['deposit_made'] ?? false),
            'client_email_verified' => (bool) ($status['email_verified'] ?? false),
            'client_phone_verified' => (bool) ($status['phone_verified'] ?? false),
            'client_identity_verified' => (bool) ($status['identity_verified'] ?? false),
            'client_profile_complete' => (bool) ($status['profile_complete'] ?? false),
            'client_freelancer_verified' => (bool) ($status['freelancer_verified_user'] ?? false),
        ];
    }

    private function toUsd(int|float|null $amount, ?float $exchangeRate): ?float
    {
        if ($amount === null || $exchangeRate === null) {
            return null;
        }

        return round($amount * $exchangeRate, 2);
    }

    private function timestamp(?int $unixTime): ?CarbonImmutable
    {
        return $unixTime === null ? null : CarbonImmutable::createFromTimestamp($unixTime);
    }
}
