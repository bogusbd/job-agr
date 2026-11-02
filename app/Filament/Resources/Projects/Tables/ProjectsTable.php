<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Tables;

use App\Enums\ProjectType;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\ProjectInfolist;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\QueryBuilder\Constraints\BooleanConstraint;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\NumberConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('submitted_at', 'desc')
            ->recordUrl(null)
            ->recordClasses(fn (Project $record): ?string => $record->is_viewed ? 'opacity-60' : null)
            ->columns([
                IconColumn::make('is_viewed')
                    ->label('')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedEye)
                    ->falseIcon(Heroicon::OutlinedSparkles)
                    ->falseColor('primary')
                    ->trueColor('gray'),
                TextColumn::make('title')
                    ->searchable(['title', 'description'])
                    ->weight(fn (Project $record): ?string => $record->is_viewed ? null : 'bold')
                    ->description(fn (Project $record): ?string => $record->preview_description)
                    ->wrap()
                    ->lineClamp(3)
                    ->url(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record])),
                TextColumn::make('skills.name')
                    ->badge()
                    ->limitList(4)
                    ->expandableLimitedList()
                    ->toggleable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('budget')
                    ->state(fn (Project $record): string => ProjectInfolist::formatBudget($record))
                    ->description(fn (Project $record): string => '≈ $'.number_format((float) $record->budget_max_usd).' max')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('budget_max_usd', $direction)),
                TextColumn::make('bid_count')
                    ->label('Bids')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('bid_avg_usd')
                    ->label('Avg bid (USD)')
                    ->money('USD')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('client.country_name')
                    ->label('Client country')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('language')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('submitted_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active on Freelancer')
                    ->default(true),
                SelectFilter::make('type')
                    ->options(ProjectType::class)
                    ->multiple(),
                SelectFilter::make('skills')
                    ->relationship('skills', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),
                SelectFilter::make('currency_code')
                    ->label('Currency')
                    ->multiple()
                    ->options(fn (): array => Project::query()->whereNotNull('currency_code')->distinct()->orderBy('currency_code')->pluck('currency_code', 'currency_code')->all()),
                SelectFilter::make('language')
                    ->multiple()
                    ->options(fn (): array => Project::query()->whereNotNull('language')->distinct()->orderBy('language')->pluck('language', 'language')->all()),
                Filter::make('budget_usd')
                    ->label('Budget (USD)')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('budget_from')
                                ->label('Budget from, $')
                                ->numeric(),
                            TextInput::make('budget_to')
                                ->label('Budget to, $')
                                ->numeric(),
                        ]),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['budget_from'] ?? null), fn (Builder $query): Builder => $query->where('budget_max_usd', '>=', $data['budget_from']))
                        ->when(filled($data['budget_to'] ?? null), fn (Builder $query): Builder => $query->where('budget_min_usd', '<=', $data['budget_to'])))
                    ->indicateUsing(fn (array $data): ?string => filled($data['budget_from'] ?? null) || filled($data['budget_to'] ?? null)
                        ? 'Budget: $'.($data['budget_from'] ?? '0').' – $'.($data['budget_to'] ?? '∞')
                        : null),
                Filter::make('max_bids')
                    ->schema([
                        TextInput::make('max_bids')
                            ->label('Max bids')
                            ->numeric(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['max_bids'] ?? null), fn (Builder $query): Builder => $query->where('bid_count', '<=', $data['max_bids'])))
                    ->indicateUsing(fn (array $data): ?string => filled($data['max_bids'] ?? null) ? "Bids ≤ {$data['max_bids']}" : null),
                Filter::make('submitted_at')
                    ->schema([
                        Grid::make(2)->schema([
                            DatePicker::make('submitted_from'),
                            DatePicker::make('submitted_until'),
                        ]),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['submitted_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('submitted_at', '>=', $date))
                        ->when($data['submitted_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('submitted_at', '<=', $date))),
                TernaryFilter::make('client_payment_verified')
                    ->label('Client payment verified')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('client', fn (Builder $query): Builder => $query->where('is_payment_verified', true)),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('client', fn (Builder $query): Builder => $query->where('is_payment_verified', true)),
                    ),
                QueryBuilder::make()
                    ->constraints([
                        TextConstraint::make('title'),
                        TextConstraint::make('description'),
                        NumberConstraint::make('budget_min_usd')->label('Budget min (USD)'),
                        NumberConstraint::make('budget_max_usd')->label('Budget max (USD)'),
                        NumberConstraint::make('bid_count')->integer(),
                        NumberConstraint::make('bid_avg_usd')->label('Average bid (USD)'),
                        NumberConstraint::make('hourly_hours')->integer(),
                        DateConstraint::make('submitted_at'),
                        BooleanConstraint::make('is_featured'),
                        BooleanConstraint::make('is_urgent'),
                        BooleanConstraint::make('is_nda'),
                        BooleanConstraint::make('is_local'),
                        RelationshipConstraint::make('skills')
                            ->multiple()
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('name')
                                    ->searchable()
                                    ->multiple(),
                            ),
                        NumberConstraint::make('client.rating')->label('Client rating'),
                        BooleanConstraint::make('client.is_payment_verified')->label('Client payment verified'),
                        TextConstraint::make('client.country_name')->label('Client country'),
                    ]),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->recordActions([
                ViewAction::make()
                    ->iconButton(),
                Action::make('toggleViewed')
                    ->iconButton()
                    ->tooltip(fn (Project $record): string => $record->is_viewed ? 'Mark as new' : 'Mark as viewed')
                    ->icon(fn (Project $record): Heroicon => $record->is_viewed ? Heroicon::OutlinedEyeSlash : Heroicon::OutlinedEye)
                    ->action(fn (Project $record) => $record->update([
                        'is_viewed' => ! $record->is_viewed,
                        'viewed_at' => $record->is_viewed ? null : now(),
                    ])),
                self::toggleGarbageAction()
                    ->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('markViewed')
                        ->label('Mark as viewed')
                        ->icon(Heroicon::OutlinedEye)
                        ->action(fn (Collection $records) => Project::query()
                            ->whereKey($records->modelKeys())
                            ->update(['is_viewed' => true, 'viewed_at' => now()]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('markGarbage')
                        ->label('Move to garbage')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->action(fn (Collection $records) => Project::query()
                            ->whereKey($records->modelKeys())
                            ->update(['is_garbage' => true]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('restoreFromGarbage')
                        ->label('Restore from garbage')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->action(fn (Collection $records) => Project::query()
                            ->whereKey($records->modelKeys())
                            ->update(['is_garbage' => false]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function toggleGarbageAction(): Action
    {
        return Action::make('toggleGarbage')
            ->label(fn (Project $record): string => $record->is_garbage ? 'Restore' : 'Garbage')
            ->tooltip(fn (Project $record): string => $record->is_garbage ? 'Restore from garbage' : 'Not interested — hide')
            ->icon(fn (Project $record): Heroicon => $record->is_garbage ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedTrash)
            ->color(fn (Project $record): string => $record->is_garbage ? 'gray' : 'danger')
            ->action(fn (Project $record) => $record->update(['is_garbage' => ! $record->is_garbage]));
    }
}
