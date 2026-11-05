<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Tables\ProjectsTable;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property Project $record
 */
final class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->markAsViewed();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openOnFreelancer')
                ->label('Open on Freelancer')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn (Project $record): string => $record->url(), shouldOpenInNewTab: true),
            ProjectsTable::toggleGarbageAction(),
        ];
    }
}
