<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'new' => Tab::make('New')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_garbage', false)->where('is_viewed', false)),
            'all' => Tab::make('All')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_garbage', false)),
            'viewed' => Tab::make('Viewed')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_garbage', false)->where('is_viewed', true)),
            'garbage' => Tab::make('Garbage')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_garbage', true)),
        ];
    }
}
