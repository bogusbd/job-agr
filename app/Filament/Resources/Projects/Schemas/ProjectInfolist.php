<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Project;
use Filament\Actions\Action;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make(fn (Project $record): string => $record->title)
                    ->columnSpan(2)
                    ->afterHeader([
                        Action::make('openOnFreelancer')
                            ->label('Open on Freelancer')
                            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                            ->iconButton()
                            ->tooltip('Open on Freelancer')
                            ->url(fn (Project $record): string => $record->url(), shouldOpenInNewTab: true),
                    ])
                    ->schema([
                        TextEntry::make('primary_skill_slug')
                            ->label('Main category')
                            ->badge()
                            ->formatStateUsing(fn (Project $record): ?string => $record->primarySkillLabel())
                            ->helperText(fn (Project $record): ?string => $record->primarySkill?->category_name)
                            ->placeholder('—'),
                        TextEntry::make('description')
                            ->prose()
                            ->formatStateUsing(fn (?string $state): string => nl2br(e($state ?? '')))
                            ->html(),
                        TextEntry::make('skills.name')
                            ->badge(),
                    ]),
                Group::make([
                    Section::make('Details')
                        ->compact()
                        ->inlineLabel()
                        ->schema([
                            TextEntry::make('type')
                                ->badge(),
                            TextEntry::make('budget')
                                ->state(fn (Project $record): string => self::formatBudget($record)),
                            TextEntry::make('budget_usd')
                                ->label('Budget (USD)')
                                ->state(fn (Project $record): string => '$'.number_format((float) $record->budget_min_usd).' – $'.number_format((float) $record->budget_max_usd)),
                            TextEntry::make('hourly_hours')
                                ->label('Commitment')
                                ->state(fn (Project $record): ?string => $record->hourly_hours ? "{$record->hourly_hours} h / {$record->hourly_interval}" : null)
                                ->placeholder('—'),
                            TextEntry::make('bid_count')
                                ->label('Bids'),
                            TextEntry::make('bid_avg')
                                ->label('Average bid')
                                ->numeric(decimalPlaces: 2)
                                ->prefix(fn (Project $record): string => (string) $record->currency_sign),
                            TextEntry::make('language'),
                            TextEntry::make('submitted_at')
                                ->dateTime()
                                ->since(),
                            TextEntry::make('remote_updated_at')
                                ->label('Updated')
                                ->dateTime(),
                            IconEntry::make('is_active')
                                ->label('Active')
                                ->boolean(),
                            IconEntry::make('is_garbage')
                                ->label('Garbage')
                                ->boolean(),
                        ]),
                    Section::make('Client')
                        ->compact()
                        ->inlineLabel()
                        ->schema([
                            TextEntry::make('client_country_name')
                                ->label('Location')
                                ->state(fn (Project $record): string => mb_trim($record->clientCountryFlag().' '.collect([$record->client_city, $record->client_country_name])->filter()->implode(', ')))
                                ->placeholder('—'),
                            TextEntry::make('client_registered_at')
                                ->label('Member since')
                                ->date()
                                ->placeholder('—'),
                            TextEntry::make('client_rating')
                                ->label('Rating')
                                ->state(fn (Project $record): string => $record->hasNewClient()
                                    ? 'New client'
                                    : '★ '.number_format((float) $record->client_rating, 2)." ({$record->client_reviews_count})")
                                ->icon(Heroicon::User)
                                ->iconColor(fn (Project $record): string => $record->hasNewClient() ? 'warning' : 'success'),
                            TextEntry::make('client_completed_projects_count')
                                ->label('Completed')
                                ->suffix(fn (Project $record): ?string => $record->client_completion_rate !== null ? " ({$record->client_completion_rate}%)" : null),
                            Flex::make([
                                self::verificationEntry('client_payment_verified', 'Payment verified', Heroicon::CreditCard),
                                self::verificationEntry('client_deposit_made', 'Deposit made', Heroicon::Banknotes),
                                self::verificationEntry('client_email_verified', 'Email verified', Heroicon::Envelope),
                                self::verificationEntry('client_phone_verified', 'Phone verified', Heroicon::Phone),
                                self::verificationEntry('client_identity_verified', 'Identity verified', Heroicon::Identification),
                                self::verificationEntry('client_profile_complete', 'Profile complete', Heroicon::UserCircle),
                            ]),
                        ]),
                ])->columnSpan(1),
            ]);
    }

    public static function formatBudget(Project $record): string
    {
        $sign = $record->currency_sign ?? '';
        $range = $sign.number_format((float) $record->budget_min).' – '.$sign.number_format((float) $record->budget_max);

        return mb_trim($range.' '.$record->currency_code);
    }

    /**
     * A client verification flag shown as an unlabeled icon (green when true, red when false) with a tooltip.
     */
    private static function verificationEntry(string $name, string $label, Heroicon $icon): IconEntry
    {
        return IconEntry::make($name)
            ->label($label)
            ->hiddenLabel()
            ->boolean()
            ->trueIcon($icon)
            ->falseIcon($icon)
            ->trueColor('success')
            ->falseColor('danger')
            ->tooltip(fn (?bool $state): string => $label.': '.($state ? 'yes' : 'no'))
            ->grow(false);
    }
}
