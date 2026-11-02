<?php

declare(strict_types=1);

namespace App\Services\Freelancer;

use App\Models\Client;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Imports Freelancer.com projects, their skills and owners into the local database.
 *
 * Local-only columns (`is_viewed`, `viewed_at`, `is_garbage`) are never touched by the sync.
 */
final readonly class ProjectSyncService
{
    public function __construct(private FreelancerApiService $api) {}

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
            $imported += $this->storeProjects($response['projects'], $response['users']);

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
     * Fetch owner details for projects whose client has not been stored yet.
     */
    public function syncMissingClients(int $chunkSize = 100): int
    {
        $stored = 0;

        Project::query()
            ->whereNotNull('client_id')
            ->whereNotIn('client_id', Client::query()->select('id'))
            ->distinct()
            ->orderBy('client_id')
            ->pluck('client_id')
            ->chunk($chunkSize)
            ->each(function ($clientIds) use (&$stored): void {
                $stored += $this->storeClients($this->api->getUsers($clientIds->values()->all()));
            });

        return $stored;
    }

    /**
     * @param  list<array<string, mixed>>  $projects
     * @param  array<int|string, array<string, mixed>>  $users
     */
    public function storeProjects(array $projects, array $users = []): int
    {
        if ($projects === []) {
            return 0;
        }

        DB::transaction(function () use ($projects, $users): void {
            $this->storeClients($users);
            $this->storeSkills($projects);

            Project::query()->upsert(
                array_map($this->mapProject(...), $projects),
                ['id'],
                array_keys(Arr::except($this->mapProject($projects[0]), ['id', 'created_at'])),
            );

            $this->syncProjectSkills($projects);
        });

        return count($projects);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $users
     */
    public function storeClients(array $users): int
    {
        $rows = array_values(array_map($this->mapClient(...), array_filter($users, fn (array $user): bool => isset($user['id']))));

        if ($rows === []) {
            return 0;
        }

        Client::query()->upsert($rows, ['id'], array_keys(Arr::except($rows[0], ['id', 'created_at'])));

        return count($rows);
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
            'client_id' => $project['owner_id'] ?? null,
            'title' => $project['title'] ?? '',
            'seo_url' => $project['seo_url'] ?? null,
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
            'country_code' => $project['location']['country']['code'] ?? null,
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
     * @param  array<string, mixed>  $user
     * @return array<string, mixed>
     */
    private function mapClient(array $user): array
    {
        $now = now();

        return [
            'id' => $user['id'],
            'username' => $user['username'] ?? null,
            'display_name' => $user['display_name'] ?? null,
            'public_name' => $user['public_name'] ?? null,
            'company' => filled($user['company'] ?? null) ? $user['company'] : null,
            'country_code' => $user['location']['country']['code'] ?? null,
            'country_name' => $user['location']['country']['name'] ?? null,
            'city' => $user['location']['city'] ?? null,
            'timezone' => $user['timezone']['timezone'] ?? null,
            'primary_language' => $user['primary_language'] ?? null,
            'registered_at' => $this->timestamp($user['registration_date'] ?? null),
            'is_payment_verified' => $user['status']['payment_verified'] ?? null,
            'is_email_verified' => $user['status']['email_verified'] ?? null,
            'is_deposit_made' => $user['status']['deposit_made'] ?? null,
            'is_phone_verified' => $user['status']['phone_verified'] ?? null,
            'is_identity_verified' => $user['status']['identity_verified'] ?? null,
            'rating' => $user['employer_reputation']['entire_history']['overall'] ?? null,
            'reviews_count' => $user['employer_reputation']['entire_history']['reviews'] ?? null,
            'raw' => json_encode($user, JSON_UNESCAPED_UNICODE),
            'fetched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
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
