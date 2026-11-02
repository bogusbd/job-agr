<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Tables;

use App\Enums\ProjectType;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\ProjectInfolist;
use App\Models\Project;
use App\Models\Skill;
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
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\Layout\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\View\ComponentAttributeBag;

use function Filament\Support\generate_icon_html;

final class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('submitted_at', 'desc')
            ->recordUrl(null)
            ->recordClasses(fn (Project $record): ?string => $record->is_viewed ? 'opacity-60' : null)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['primarySkill', 'skills']))
            ->columns([
                Split::make([
                    IconColumn::make('is_viewed')
                        ->label('')
                        ->boolean()
                        ->trueIcon(Heroicon::OutlinedEye)
                        ->falseIcon(Heroicon::OutlinedSparkles)
                        ->falseColor('primary')
                        ->trueColor('gray')
                        ->grow(false),
                    Split::make([
                        TextColumn::make('title')
                            ->searchable(['title', 'description'])
                            ->weight(fn (Project $record): FontWeight => $record->is_viewed ? FontWeight::Medium : FontWeight::Bold)
                            ->wrap()
                            ->lineClamp(2)
                            ->url(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record]))
                            ->grow(false),
                        TextColumn::make('primary_skill_slug')
                            ->label('Main category')
                            ->badge()
                            ->formatStateUsing(fn (Project $record): ?string => $record->primarySkillLabel())
                            ->sortable()
                            ->grow(false),
                    ]),
                    Stack::make([
                        TextColumn::make('client_country_code')
                            ->label('Client country')
                            ->formatStateUsing(fn (Project $record): string => mb_trim($record->clientCountryFlag().' '.mb_strtoupper((string) $record->client_country_code)))
                            ->tooltip(fn (Project $record): ?string => $record->client_country_name)
                            ->placeholder('—')
                            ->sortable(),
                        TextColumn::make('client_rating')
                            ->label('Client rating')
                            ->state(fn (Project $record): string => self::clientReputation($record))
                            ->formatStateUsing(fn (Project $record, string $state): HtmlString => self::clientReputationHtml($record, $state))
                            ->icon(Heroicon::CreditCard)
                            ->iconColor(fn (Project $record): string => $record->client_payment_verified ? 'success' : 'danger')
                            ->tooltip(fn (Project $record): string => collect([
                                $record->hasNewClient() ? 'New client (no reviews or completed projects)' : 'Existing client',
                                $record->client_payment_verified ? 'Payment verified' : 'Payment not verified',
                            ])->filter()->implode(' · '))
                            ->color('gray')
                            ->sortable(),
                    ])
                        ->space(1)
                        ->extraAttributes(['class' => 'w-36'])
                        ->grow(false),
                    Stack::make([
                        TextColumn::make('budget')
                            ->state(fn (Project $record): string => ProjectInfolist::formatBudget($record))
                            ->weight(FontWeight::SemiBold)
                            ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('budget_max_usd', $direction)),
                        TextColumn::make('type')
                            ->badge()
                            ->sortable(),
                    ])
                        ->space(1)
                        ->alignment(Alignment::End)
                        ->extraAttributes(['class' => 'w-44'])
                        ->grow(false),
                    Stack::make([
                        TextColumn::make('bid_count')
                            ->label('Bids')
                            ->formatStateUsing(fn (int $state): string => trans_choice(':count bid|:count bids', $state))
                            ->sortable(),
                        TextColumn::make('submitted_at')
                            ->label('Submitted')
                            ->since()
                            ->dateTimeTooltip()
                            ->color('gray')
                            ->sortable(),
                    ])
                        ->space(1)
                        ->alignment(Alignment::End)
                        ->extraAttributes(['class' => 'w-28'])
                        ->grow(false),
                ])->from('md'),
                View::make('filament.tables.projects.project-details'),
            ])
            ->filters([
                SelectFilter::make('primary_skill_slug')
                    ->label('Main category')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => self::primarySkillOptions()),
                SelectFilter::make('currency_code')
                    ->label('Currency')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => self::currencyOptions()),
                TernaryFilter::make('is_active')
                    ->label('Active on Freelancer')
                    ->default(true),
                SelectFilter::make('type')
                    ->options(ProjectType::class)
                    ->multiple(),
                SelectFilter::make('category_group')
                    ->label('Category group')
                    ->multiple()
                    ->options(fn (): array => Skill::query()->whereNotNull('category_name')->distinct()->orderBy('category_name')->pluck('category_name', 'category_name')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['values'] ?? null),
                        fn (Builder $query): Builder => $query->whereHas('primarySkill', fn (Builder $query): Builder => $query->whereIn('category_name', $data['values'])),
                    )),
                SelectFilter::make('skills')
                    ->relationship('skills', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),
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
                SelectFilter::make('client_country_code')
                    ->label('Client country')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => self::clientCountryOptions()),
                SelectFilter::make('exclude_client_country')
                    ->label('Exclude client countries')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => self::clientCountryOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['values'] ?? null),
                        fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                            ->whereNull('client_country_code')
                            ->orWhereNotIn('client_country_code', $data['values'])),
                    )),
                TernaryFilter::make('client_history')
                    ->label('Client history')
                    ->placeholder('All clients')
                    ->trueLabel('Existing clients (with reviews or completed projects)')
                    ->falseLabel('New clients (no history)')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                            ->where('client_reviews_count', '>', 0)
                            ->orWhere('client_completed_projects_count', '>', 0)),
                        false: fn (Builder $query): Builder => $query
                            ->where('client_reviews_count', 0)
                            ->where('client_completed_projects_count', 0),
                    ),
                TernaryFilter::make('client_payment_verified')
                    ->label('Client payment verified'),
                TernaryFilter::make('client_deposit_made')
                    ->label('Client deposit made'),
                Filter::make('client_rating')
                    ->schema([
                        TextInput::make('min_client_rating')
                            ->label('Min client rating')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(5)
                            ->step(0.1),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['min_client_rating'] ?? null), fn (Builder $query): Builder => $query->where('client_rating', '>=', $data['min_client_rating'])))
                    ->indicateUsing(fn (array $data): ?string => filled($data['min_client_rating'] ?? null) ? "Client rating ≥ {$data['min_client_rating']}" : null),
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
                        BooleanConstraint::make('is_sealed'),
                        BooleanConstraint::make('is_fulltime'),
                        BooleanConstraint::make('is_qualified'),
                        BooleanConstraint::make('is_premium'),
                        BooleanConstraint::make('is_recruiter'),
                        BooleanConstraint::make('is_enterprise'),
                        TextConstraint::make('language'),
                        TextConstraint::make('currency_code'),
                        TextConstraint::make('client_country_name')->label('Client country'),
                        TextConstraint::make('client_city')->label('Client city'),
                        DateConstraint::make('client_registered_at')->label('Client registered'),
                        NumberConstraint::make('client_rating')->label('Client rating'),
                        NumberConstraint::make('client_reviews_count')->label('Client reviews')->integer(),
                        NumberConstraint::make('client_completed_projects_count')->label('Client completed projects')->integer(),
                        NumberConstraint::make('client_completion_rate')->label('Client completion rate, %'),
                        BooleanConstraint::make('client_payment_verified')->label('Client payment verified'),
                        BooleanConstraint::make('client_deposit_made')->label('Client deposit made'),
                        BooleanConstraint::make('client_email_verified')->label('Client email verified'),
                        BooleanConstraint::make('client_phone_verified')->label('Client phone verified'),
                        BooleanConstraint::make('client_identity_verified')->label('Client identity verified'),
                        BooleanConstraint::make('client_profile_complete')->label('Client profile complete'),
                    ]),
            ], layout: FiltersLayout::AboveContent)
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

    /**
     * Client rating with reviews, or "New" for a client without any history.
     */
    private static function clientReputation(Project $record): string
    {
        if ($record->hasNewClient()) {
            return 'New';
        }

        return '★ '.number_format((float) $record->client_rating, 1)." ({$record->client_reviews_count})";
    }

    /**
     * A user icon (amber for a new client, green for a client with history) followed by the rating.
     */
    private static function clientReputationHtml(Project $record, string $reputation): HtmlString
    {
        $isNewClient = $record->hasNewClient();

        $icon = generate_icon_html(
            Heroicon::User,
            attributes: new ComponentAttributeBag([
                'class' => $isNewClient ? 'text-warning-500' : 'text-success-600 dark:text-success-400',
            ]),
            size: IconSize::Small,
        )?->toHtml();

        $text = $isNewClient ? '' : '<span>'.e($reputation).'</span>';

        return new HtmlString("<span class=\"inline-flex items-center gap-1\">{$icon}{$text}</span>");
    }

    /**
     * @return array<string, string>
     */
    private static function clientCountryOptions(): array
    {
        return Project::query()
            ->whereNotNull('client_country_code')
            ->distinct()
            ->orderBy('client_country_name')
            ->pluck('client_country_name', 'client_country_code')
            ->all();
    }

    /**
     * Main category options keyed by URL slug, labelled with the skill name when the skill is known.
     *
     * @return array<string, string>
     */
    private static function primarySkillOptions(): array
    {
        return Project::query()
            ->whereNotNull('primary_skill_slug')
            ->leftJoin('skills', 'skills.id', '=', 'projects.primary_skill_id')
            ->groupBy('projects.primary_skill_slug', 'skills.name')
            ->selectRaw('projects.primary_skill_slug as slug, skills.name as name, count(*) as projects_count')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [$row->slug => ($row->name ?? Str::headline($row->slug))." ({$row->projects_count})"])
            ->sort()
            ->all();
    }

    /**
     * Currency options keyed by code, with the number of projects in each currency.
     *
     * @return array<string, string>
     */
    private static function currencyOptions(): array
    {
        return Project::query()
            ->whereNotNull('currency_code')
            ->groupBy('currency_code')
            ->selectRaw('currency_code, count(*) as projects_count')
            ->orderBy('currency_code')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [$row->currency_code => "{$row->currency_code} ({$row->projects_count})"])
            ->all();
    }
}
