<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Project;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use League\CommonMark\Extension\Autolink\AutolinkExtension;

final class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make(fn (Project $record): string => $record->title)
                    ->description(fn (Project $record): ?string => collect([$record->primarySkillLabel(), $record->primarySkill?->category_name])->filter()->implode(' · ') ?: null)
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
                        TextEntry::make('description')
                            ->prose()
                            ->markdown()
                            ->commonMarkOptions([
                                'html_input' => 'escape',
                                'renderer' => ['soft_break' => "<br />\n"],
                            ])
                            ->commonMarkExtensions([new AutolinkExtension]),
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
                            TextEntry::make('bid_avg')
                                ->label('Average bid')
                                ->state(fn (Project $record): string => self::formatAverageBid($record)),
                            TextEntry::make('language'),
                            TextEntry::make('submitted_at')
                                ->dateTime()
                                ->since(),
                        ]),
                    Section::make('Location')
                        ->compact()
                        ->inlineLabel()
                        ->visible(fn (Project $record): bool => $record->is_local || $record->hasLocation())
                        ->schema([
                            TextEntry::make('is_local')
                                ->label('Work')
                                ->formatStateUsing(fn (bool $state): string => $state ? 'On-site' : 'Remote, location specified')
                                ->badge()
                                ->color(fn (bool $state): string => $state ? 'warning' : 'info'),
                            TextEntry::make('location_address')
                                ->label('Address')
                                ->state(fn (Project $record): ?string => $record->locationLabel())
                                ->icon(Heroicon::MapPin)
                                ->url(fn (Project $record): ?string => $record->locationMapUrl(), shouldOpenInNewTab: true)
                                ->tooltip(fn (Project $record): ?string => $record->locationMapUrl() ? 'Open in Google Maps' : null)
                                ->placeholder('—'),
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
                                ->label('Member for')
                                ->formatStateUsing(fn (CarbonInterface $state): string => $state->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, parts: 2))
                                ->dateTooltip()
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
                    Section::make('Status')
                        ->compact()
                        ->inlineLabel()
                        ->collapsible()
                        ->collapsed()
                        ->schema([
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
                ])->columnSpan(1),
            ]);
    }

    /**
     * Average bid in the project currency with the number of bids, e.g. "€83.42 (35 Bids)".
     */
    public static function formatAverageBid(Project $record): string
    {
        if ((int) $record->bid_count === 0) {
            return 'No bids yet';
        }

        $bids = trans_choice(':count Bid|:count Bids', (int) $record->bid_count);

        return ($record->currency_sign ?? '').number_format((float) $record->bid_avg, 2)." ({$bids})";
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
