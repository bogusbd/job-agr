<?php

declare(strict_types=1);

use App\Enums\ProjectType;
use App\Models\Project;
use App\Models\Skill;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function freelancerProject(int $id, array $overrides = []): array
{
    return array_replace_recursive([
        'id' => $id,
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
        'owner_info' => [
            'city' => 'Helsinki',
            'country' => ['name' => 'Finland', 'code' => 'fi'],
            'registration_date' => 1_793_614_216,
            'reputation' => ['entire_history' => ['overall' => 0.0], 'earnings_score' => 0.0],
            'status' => ['payment_verified' => false, 'email_verified' => true, 'deposit_made' => false, 'phone_verified' => true],
        ],
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $projects
 * @return array<string, mixed>
 */
function freelancerProjectsResponse(array $projects, int $total): array
{
    return ['status' => 'success', 'result' => ['projects' => $projects, 'total_count' => $total]];
}

beforeEach(function () {
    config()->set('services.freelancer.page_size', 2);
});

it('imports every page of active projects with their skills', function () {
    Http::fake([
        'www.freelancer.com/api/projects/0.1/projects/active/*' => function (Request $request) {
            return match ($request->data()['offset']) {
                '0' => Http::response(freelancerProjectsResponse([freelancerProject(1), freelancerProject(2)], 3)),
                '2' => Http::response(freelancerProjectsResponse([freelancerProject(3, ['jobs' => []])], 3)),
            };
        },
    ]);

    artisan('freelancer:sync-projects')->assertSuccessful();

    expect(Project::count())->toBe(3);

    $project = Project::with('skills')->find(1);

    expect($project)
        ->type->toBe(ProjectType::Hourly)
        ->currency_code->toBe('INR')
        ->budget_max_usd->toBe('20.00')
        ->bid_avg_usd->toBe('15.00')
        ->is_featured->toBeTrue()
        ->is_nda->toBeTrue()
        ->hourly_hours->toBe(40)
        ->submitted_at->getTimestamp()->toBe(1_793_350_406)
        ->and($project->skills->pluck('name')->sort()->values()->all())->toBe(['Laravel', 'PHP']);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'owner_info=true'));
});

it('stores the owner profile of a new client', function () {
    Http::fake([
        'www.freelancer.com/api/projects/0.1/projects/active/*' => Http::response(freelancerProjectsResponse([freelancerProject(1)], 1)),
    ]);

    artisan('freelancer:sync-projects')->assertSuccessful();

    $project = Project::find(1);

    expect($project)
        ->client_city->toBe('Helsinki')
        ->client_country_code->toBe('FI')
        ->client_country_name->toBe('Finland')
        ->client_registered_at->getTimestamp()->toBe(1_793_614_216)
        ->client_reviews_count->toBe(0)
        ->client_completed_projects_count->toBe(0)
        ->client_completion_rate->toBeNull()
        ->client_payment_verified->toBeFalse()
        ->client_phone_verified->toBeTrue()
        ->and($project->hasNewClient())->toBeTrue();
});

it('stores the reputation of an existing client', function () {
    Http::fake([
        'www.freelancer.com/api/projects/0.1/projects/active/*' => Http::response(freelancerProjectsResponse([freelancerProject(1, ['owner_info' => [
            'reputation' => ['entire_history' => ['overall' => 4.9969269451041, 'reviews' => 24, 'complete' => 25, 'completion_rate' => 0.96]],
            'status' => ['payment_verified' => true, 'deposit_made' => true],
        ]])], 1)),
    ]);

    artisan('freelancer:sync-projects')->assertSuccessful();

    $project = Project::find(1);

    expect($project)
        ->client_rating->toBe('5.00')
        ->client_reviews_count->toBe(24)
        ->client_completed_projects_count->toBe(25)
        ->client_completion_rate->toBe('96.00')
        ->client_payment_verified->toBeTrue()
        ->client_deposit_made->toBeTrue()
        ->and($project->hasNewClient())->toBeFalse();
});

it('detects the main category from the project url', function () {
    Skill::factory()->create(['id' => 77, 'name' => 'Event Photography', 'seo_url' => 'event_photography']);

    Http::fake([
        'www.freelancer.com/api/projects/0.1/projects/active/*' => Http::response(freelancerProjectsResponse([
            freelancerProject(1, ['seo_url' => 'php/Build-api']),
            freelancerProject(2, ['seo_url' => 'event-photography/Surprise-Proposal-Capture']),
            freelancerProject(3, ['seo_url' => 'engineering-drawing/Engineer-Stamp']),
        ], 3)),
    ]);

    artisan('freelancer:sync-projects')->assertSuccessful();

    expect(Project::query()->orderBy('id')->get(['primary_skill_slug', 'primary_skill_id'])->toArray())->toBe([
        ['primary_skill_slug' => 'php', 'primary_skill_id' => 3],
        ['primary_skill_slug' => 'event-photography', 'primary_skill_id' => 77],
        ['primary_skill_slug' => 'engineering-drawing', 'primary_skill_id' => null],
    ]);
});

it('keeps local statuses when a project is synced again', function () {
    Project::factory()->viewed()->garbage()->create(['id' => 1, 'title' => 'Old title']);

    Http::fake([
        'www.freelancer.com/api/projects/0.1/projects/active/*' => Http::response(freelancerProjectsResponse([freelancerProject(1, ['bid_stats' => ['bid_count' => 42]])], 1)),
    ]);

    artisan('freelancer:sync-projects')->assertSuccessful();

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

    artisan('freelancer:sync-projects')->assertSuccessful();

    expect($closed->fresh()->is_active)->toBeFalse()
        ->and(Project::find(1)->is_active)->toBeTrue();
});

it('does not deactivate projects after a filtered sync', function () {
    $other = Project::factory()->create(['last_seen_at' => now()->subDay()]);

    Http::fake([
        'www.freelancer.com/api/projects/0.1/projects/active/*' => Http::response(freelancerProjectsResponse([freelancerProject(1)], 1)),
    ]);

    artisan('freelancer:sync-projects', ['--skill' => ['3']])->assertSuccessful();

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

    artisan('freelancer:sync-projects')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('freelancer-oauth-v1', 'secret-token'));
});

it('fails without deactivating anything when the api errors', function () {
    $project = Project::factory()->create(['last_seen_at' => now()->subDay()]);

    Http::fake([
        'www.freelancer.com/api/projects/0.1/projects/active/*' => Http::response(['status' => 'error'], 500),
    ]);

    artisan('freelancer:sync-projects')->assertFailed();

    expect($project->fresh()->is_active)->toBeTrue();
});
