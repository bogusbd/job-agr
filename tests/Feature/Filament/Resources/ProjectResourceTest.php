<?php

declare(strict_types=1);

use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Project;
use App\Models\Skill;
use Filament\Actions\Testing\TestAction;

use function Pest\Livewire\livewire;

it('can render the index page', function () {
    livewire(ListProjects::class)
        ->assertOk();
});

it('shows only new projects in the default tab', function () {
    $new = Project::factory()->create();
    $viewed = Project::factory()->viewed()->create();
    $garbage = Project::factory()->garbage()->create();

    livewire(ListProjects::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$new])
        ->assertCanNotSeeTableRecords([$viewed, $garbage]);
});

it('hides garbage everywhere except the garbage tab', function (string $tab, bool $isVisible) {
    $garbage = Project::factory()->garbage()->create();

    $table = livewire(ListProjects::class)
        ->set('activeTab', $tab)
        ->loadTable();

    $isVisible
        ? $table->assertCanSeeTableRecords([$garbage])
        : $table->assertCanNotSeeTableRecords([$garbage]);
})->with([
    'all' => ['all', false],
    'viewed' => ['viewed', false],
    'garbage' => ['garbage', true],
]);

it('marks a project as garbage', function () {
    $project = Project::factory()->create();

    livewire(ListProjects::class)
        ->loadTable()
        ->callAction(TestAction::make('toggleGarbage')->table($project))
        ->assertCanNotSeeTableRecords([$project]);

    expect($project->fresh()->is_garbage)->toBeTrue();
});

it('toggles the viewed status', function () {
    $project = Project::factory()->create();

    livewire(ListProjects::class)
        ->loadTable()
        ->callAction(TestAction::make('toggleViewed')->table($project));

    expect($project->fresh())
        ->is_viewed->toBeTrue()
        ->viewed_at->not->toBeNull();
});

it('marks selected projects as garbage in bulk', function () {
    $projects = Project::factory()->count(3)->create();

    livewire(ListProjects::class)
        ->loadTable()
        ->selectTableRecords($projects)
        ->callAction(TestAction::make('markGarbage')->table()->bulk());

    expect(Project::where('is_garbage', true)->count())->toBe(3);
});

it('marks a project as viewed when it is opened', function () {
    $project = Project::factory()->create();

    livewire(ViewProject::class, ['record' => $project->id])
        ->assertOk()
        ->assertSee($project->title);

    expect($project->fresh()->is_viewed)->toBeTrue();
});

it('filters by budget in usd', function () {
    $cheap = Project::factory()->create(['budget_min_usd' => 10, 'budget_max_usd' => 30]);
    $expensive = Project::factory()->create(['budget_min_usd' => 500, 'budget_max_usd' => 1500]);

    livewire(ListProjects::class)
        ->loadTable()
        ->filterTable('budget_usd', ['budget_from' => 250])
        ->assertCanSeeTableRecords([$expensive])
        ->assertCanNotSeeTableRecords([$cheap]);
});

it('filters by skill', function () {
    $laravel = Skill::factory()->create(['name' => 'Laravel']);
    $matching = Project::factory()->hasAttached($laravel)->create();
    $other = Project::factory()->create();

    livewire(ListProjects::class)
        ->loadTable()
        ->filterTable('skills', [$laravel->id])
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

it('hides inactive projects by default', function () {
    $inactive = Project::factory()->create(['is_active' => false]);

    livewire(ListProjects::class)
        ->set('activeTab', 'all')
        ->loadTable()
        ->assertCanNotSeeTableRecords([$inactive]);
});

it('filters by main category', function () {
    $photography = Project::factory()->create(['primary_skill_slug' => 'event-photography']);
    $drawing = Project::factory()->create(['primary_skill_slug' => 'engineering-drawing']);
    $php = Project::factory()->create(['primary_skill_slug' => 'php']);

    livewire(ListProjects::class)
        ->loadTable()
        ->filterTable('primary_skill_slug', ['event-photography', 'php'])
        ->assertCanSeeTableRecords([$photography, $php])
        ->assertCanNotSeeTableRecords([$drawing]);
});

it('filters by the category group of the main skill', function () {
    $engineering = Skill::factory()->create(['category_name' => 'Engineering & Science']);
    $writing = Skill::factory()->create(['category_name' => 'Writing & Content']);
    $matching = Project::factory()->create(['primary_skill_id' => $engineering->id]);
    $other = Project::factory()->create(['primary_skill_id' => $writing->id]);

    livewire(ListProjects::class)
        ->loadTable()
        ->filterTable('category_group', ['Engineering & Science'])
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters new and existing clients', function (bool $hasHistory) {
    $newClientProject = Project::factory()->create();
    $existingClientProject = Project::factory()->existingClient()->create();

    [$visible, $hidden] = $hasHistory ? [$existingClientProject, $newClientProject] : [$newClientProject, $existingClientProject];

    livewire(ListProjects::class)
        ->loadTable()
        ->filterTable('client_history', $hasHistory)
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$hidden]);
})->with([
    'existing clients' => [true],
    'new clients' => [false],
]);

it('excludes projects from selected client countries', function () {
    $indian = Project::factory()->create(['client_country_code' => 'in', 'client_country_name' => 'India']);
    $finnish = Project::factory()->create(['client_country_code' => 'fi', 'client_country_name' => 'Finland']);
    $unknown = Project::factory()->create(['client_country_code' => null, 'client_country_name' => null]);

    livewire(ListProjects::class)
        ->loadTable()
        ->filterTable('exclude_client_country', ['in'])
        ->assertCanSeeTableRecords([$finnish, $unknown])
        ->assertCanNotSeeTableRecords([$indian]);
});

it('filters by minimum client rating', function () {
    $trusted = Project::factory()->existingClient()->create(['client_rating' => 4.8]);
    $poor = Project::factory()->existingClient()->create(['client_rating' => 3.1]);

    livewire(ListProjects::class)
        ->loadTable()
        ->filterTable('client_rating', ['min_client_rating' => 4.5])
        ->assertCanSeeTableRecords([$trusted])
        ->assertCanNotSeeTableRecords([$poor]);
});

it('filters by several currencies', function () {
    $usd = Project::factory()->create(['currency_code' => 'USD']);
    $eur = Project::factory()->create(['currency_code' => 'EUR']);
    $inr = Project::factory()->create(['currency_code' => 'INR']);

    livewire(ListProjects::class)
        ->loadTable()
        ->filterTable('currency_code', ['USD', 'EUR'])
        ->assertCanSeeTableRecords([$usd, $eur])
        ->assertCanNotSeeTableRecords([$inr]);
});
