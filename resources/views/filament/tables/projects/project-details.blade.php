@php
    /** @var \App\Models\Project $project */
    $project = $getRecord();
    $skills = $project->skills->pluck('name');
@endphp

<div class="mt-2 flex flex-col gap-y-2 text-sm md:ps-9">
    @if (filled($project->preview_description))
        <p class="line-clamp-2 text-gray-600 dark:text-gray-400">
            {{ $project->preview_description }}
        </p>
    @endif

    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
        @if ($skills->isNotEmpty())
            <div class="flex flex-wrap items-center gap-1">
                @foreach ($skills->take(6) as $skill)
                    <x-filament::badge color="gray" size="sm">{{ $skill }}</x-filament::badge>
                @endforeach

                @if ($skills->count() > 6)
                    <span title="{{ $skills->slice(6)->implode(', ') }}">+{{ $skills->count() - 6 }}</span>
                @endif
            </div>
        @endif

        <span>≈ ${{ number_format((float) $project->budget_max_usd) }} max</span>

        @if ($project->primarySkill?->category_name)
            <span>{{ $project->primarySkill->category_name }}</span>
        @endif
    </div>
</div>
