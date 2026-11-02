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
