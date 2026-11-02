<?php

declare(strict_types=1);

use App\Enums\ProjectType;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;
use function Pest\Laravel\assertDatabaseHas;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function freelancerProject(int $id, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => $id,
        'owner_id' => 500,
        'title' => "Project {$id}",
        'status' => 'active',
        'seo_url' => "php/project-{$id}",
        'currency' => ['id' => 11, 'code' => 'INR', 'sign' => '₹', 'exchange_rate' => 0.01],
        'description' => 'Full description',
        'preview_description' => 'Preview',
        'type' => 'hourly',
        'bidperiod' => 7,
        'budget' => ['minimum' => 1000.0, 'maximum' => 2000.0],
        'hourly_project_info' => ['commitment' => ['hours' => 40, 'interval' => 'week']],
        'bid_stats' => ['bid_count' => 5, 'bid_avg' => 1500.0],
        'time_submitted' => 1_793_350_406,
        'time_updated' => 1_793_655_926,
        'upgrades' => ['featured' => true, 'urgent' => false, 'NDA' => true],
        'language' => 'en',
        'jobs' => [
            ['id' => 3, 'name' => 'PHP', 'seo_url' => 'php', 'category' => ['id' => 1, 'name' => 'Websites, IT & Software']],
            ['id' => 13, 'name' => 'Laravel', 'seo_url' => 'laravel', 'category' => ['id' => 1, 'name' => 'Websites, IT & Software']],
        ],
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $projects
 * @return array<string, mixed>
 */
function freelancerProjectsResponse(array $projects, int $total, array $users = []): array
{
    return ['status' => 'success', 'result' => ['projects' => $projects, 'users' => $users, 'total_count' => $total]];
}

function freelancerUser(int $id): array
{
    return [
        'id' => $id,
        'username' => "client{$id}",
        'display_name' => 'Client Name',
        'registration_date' => 1_075_988_742,
        'location' => ['country' => ['name' => 'Australia', 'code' => 'au'], 'city' => 'Sydney'],
        'status' => ['payment_verified' => true, 'email_verified' => true, 'deposit_made' => false],
        'employer_reputation' => ['entire_history' => ['overall' => 4.8, 'reviews' => 12]],
    ];
}

beforeEach(function () {
    config()->set('services.freelancer.page_size', 2);
});

it('imports every page of active projects with skills and clients', function () {
    Http::fake([
        'www.freelancer.com/api/projects/0.1/projects/active/*' => function (Request $request) {
            return match ($request->data()['offset']) {
                '0' => Http::response(freelancerProjectsResponse([freelancerProject(1), freelancerProject(2)], 3, ['500' => freelancerUser(500)])),
                '2' => Http::response(freelancerProjectsResponse([freelancerProject(3, ['owner_id' => 600, 'jobs' => []])], 3)),
            };
        },
        'www.freelancer.com/api/users/0.1/users/*' => Http::response(['status' => 'success', 'result' => ['users' => ['600' => freelancerUser(600)]]]),
    ]);

    artisan('freelancer:sync-projects')->assertSuccessful();

    expect(Project::count())->toBe(3)
        ->and(Client::query()->pluck('id')->sort()->values()->all())->toBe([500, 600]);

    $project = Project::with('skills')->find(1);

    expect($project)
        ->type->toBe(ProjectType::Hourly)
        ->client_id->toBe(500)
        ->currency_code->toBe('INR')
        ->budget_max_usd->toBe('20.00')
        ->bid_avg_usd->toBe('15.00')
        ->is_featured->toBeTrue()
        ->is_nda->toBeTrue()
        ->hourly_hours->toBe(40)
        ->submitted_at->getTimestamp()->toBe(1_793_350_406)
        ->and($project->skills->pluck('name')->sort()->values()->all())->toBe(['Laravel', 'PHP']);

    assertDatabaseHas(Client::class, ['id' => 500, 'country_code' => 'au', 'is_payment_verified' => true, 'rating' => 4.8]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'users/0.1/users')
        && str_contains(urldecode($request->url()), 'users[]=600')
        && ! str_contains(urldecode($request->url()), 'users[]=500'));
});

it('keeps local statuses when a project is synced again', function () {
    Project::factory()->viewed()->garbage()->create(['id' => 1, 'title' => 'Old title']);

    Http::fake([
        'www.freelancer.com/api/projects/0.1/projects/active/*' => Http::response(freelancerProjectsResponse([freelancerProject(1, ['bid_stats' => ['bid_count' => 42]])], 1)),
    ]);

    artisan('freelancer:sync-projects', ['--without-clients' => true])->assertSuccessful();

    expect(Project::find(1))
        ->title->toBe('Project 1')
        ->bid_count->toBe(42)
        ->is_viewed->toBeTrue()
        ->is_garbage->toBeTrue();
});

it('deactivates projects missing from a complete unfiltered sync', function () {
    $closed = Project::factory()->create(['last_seen_at' => now()->subDay()]);

    Http::fake([
        'www.freelancer.com/api/projects/0.1/projects/active/*' => Http::response(freelancerProjectsResponse([freelancerProject(1)], 1)),
    ]);

    artisan('freelancer:sync-projects', ['--without-clients' => true])->assertSuccessful();

    expect($closed->fresh()->is_active)->toBeFalse()
        ->and(Project::find(1)->is_active)->toBeTrue();
});

it('does not deactivate projects after a filtered sync', function () {
    $other = Project::factory()->create(['last_seen_at' => now()->subDay()]);

    Http::fake([
        'www.freelancer.com/api/projects/0.1/projects/active/*' => Http::response(freelancerProjectsResponse([freelancerProject(1)], 1)),
    ]);

    artisan('freelancer:sync-projects', ['--skill' => ['3'], '--without-clients' => true])->assertSuccessful();

    expect($other->fresh()->is_active)->toBeTrue();
});

it('passes search filters to the api', function () {
    Http::fake([
        'www.freelancer.com/api/projects/0.1/projects/active/*' => Http::response(freelancerProjectsResponse([], 0)),
    ]);

    artisan('freelancer:sync-projects', [
        '--query' => 'laravel api',
        '--skill' => ['3', '13'],
        '--type' => ['fixed'],
        '--param' => ['min_avg_price=100', 'countries[]=us'],
        '--without-clients' => true,
    ])->assertSuccessful();

    Http::assertSent(function (Request $request): bool {
        $query = urldecode((string) parse_url($request->url(), PHP_URL_QUERY));

        return str_contains($query, 'query=laravel api')
            && str_contains($query, 'jobs[]=3&jobs[]=13')
            && str_contains($query, 'project_types[]=fixed')
            && str_contains($query, 'min_avg_price=100')
            && str_contains($query, 'countries[]=us')
            && str_contains($query, 'full_description=true');
    });
});

it('sends the oauth token when configured', function () {
    config()->set('services.freelancer.oauth_token', 'secret-token');

    Http::fake([
        'www.freelancer.com/api/projects/0.1/projects/active/*' => Http::response(freelancerProjectsResponse([], 0)),
    ]);

    artisan('freelancer:sync-projects', ['--without-clients' => true])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('freelancer-oauth-v1', 'secret-token'));
});

it('fails without deactivating anything when the api errors', function () {
    $project = Project::factory()->create(['last_seen_at' => now()->subDay()]);

    Http::fake([
        'www.freelancer.com/api/projects/0.1/projects/active/*' => Http::response(['status' => 'error'], 500),
    ]);

    artisan('freelancer:sync-projects', ['--without-clients' => true])->assertFailed();

    expect($project->fresh()->is_active)->toBeTrue();
});
