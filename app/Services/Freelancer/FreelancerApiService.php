<?php

declare(strict_types=1);

namespace App\Services\Freelancer;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Thin wrapper around the Freelancer.com REST API.
 *
 * @see https://developers.freelancer.com/docs/projects/projects
 */
final class FreelancerApiService
{
    /**
     * Flags that make the active projects search return full project and owner details.
     *
     * @var array<string, bool>
     */
    public const array PROJECT_DETAIL_FLAGS = [
        'full_description' => true,
        'job_details' => true,
        'upgrade_details' => true,
        'location_details' => true,
        'user_details' => true,
        'user_country_details' => true,
        'user_status' => true,
        'user_employer_reputation' => true,
        'compact' => true,
    ];

    /**
     * Flags that make the users endpoint return the details stored on a client.
     *
     * @var array<string, bool>
     */
    public const array USER_DETAIL_FLAGS = [
        'country_details' => true,
        'status' => true,
        'employer_reputation' => true,
        'compact' => true,
    ];

    /**
     * Search active projects (GET /projects/0.1/projects/active/).
     *
     * @param  array<string, mixed>  $parameters  Any query parameter supported by the endpoint (query, jobs, project_types, min_avg_price, from_time, ...).
     * @return array{projects: list<array<string, mixed>>, users: array<int|string, array<string, mixed>>, total_count: int}
     */
    public function searchActiveProjects(array $parameters = []): array
    {
        $result = $this->get('projects/0.1/projects/active/', [...self::PROJECT_DETAIL_FLAGS, ...$parameters]);

        return [
            'projects' => $result['projects'] ?? [],
            'users' => $result['users'] ?? [],
            'total_count' => (int) ($result['total_count'] ?? 0),
        ];
    }

    /**
     * Walk through every page of the active projects search.
     *
     * @param  array<string, mixed>  $parameters
     * @return Generator<int, array{projects: list<array<string, mixed>>, users: array<int|string, array<string, mixed>>, total_count: int}>
     */
    public function paginateActiveProjects(array $parameters = [], ?int $maxPages = null): Generator
    {
        $pageSize = (int) config('services.freelancer.page_size');
        $offset = 0;
        $page = 0;

        do {
            $response = $this->searchActiveProjects([...$parameters, 'limit' => $pageSize, 'offset' => $offset]);

            yield $page => $response;

            $page++;
            $offset += $pageSize;
            $hasMore = count($response['projects']) > 0 && $offset < $response['total_count'];

            if ($hasMore) {
                Sleep::for((int) config('services.freelancer.request_delay_ms'))->milliseconds();
            }
        } while ($hasMore && ($maxPages === null || $page < $maxPages));
    }

    /**
     * Fetch users (project owners) by their IDs (GET /users/0.1/users/).
     *
     * @param  list<int>  $userIds
     * @return array<int|string, array<string, mixed>>
     */
    public function getUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $result = $this->get('users/0.1/users/', [...self::USER_DETAIL_FLAGS, 'users' => $userIds]);

        return $result['users'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function get(string $path, array $parameters): array
    {
        return $this->request()
            ->get($path, $this->buildQuery($parameters))
            ->throw()
            ->json('result') ?? [];
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl(mb_rtrim((string) config('services.freelancer.base_url'), '/').'/')
            ->acceptJson()
            ->timeout((int) config('services.freelancer.timeout'))
            ->retry(3, 1000, throw: false);

        $token = config('services.freelancer.oauth_token');

        if (filled($token)) {
            $request->withHeader('freelancer-oauth-v1', $token);
        }

        return $request;
    }

    /**
     * Freelancer expects array parameters as `jobs[]=1&jobs[]=2` and booleans as `true`/`false`.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function buildQuery(array $parameters): string
    {
        $pairs = [];

        foreach ($parameters as $key => $value) {
            if ($value === null) {
                continue;
            }

            foreach (is_array($value) ? $value : [$value] as $item) {
                $name = is_array($value) ? $key.'[]' : $key;
                $pairs[] = urlencode($name).'='.urlencode(is_bool($item) ? ($item ? 'true' : 'false') : (string) $item);
            }
        }

        return implode('&', $pairs);
    }
}
