<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Project;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Project')
                    ->columnSpan(2)
                    ->schema([
                        TextEntry::make('title')
                            ->url(fn (Project $record): string => $record->url(), shouldOpenInNewTab: true),
                        TextEntry::make('skills.name')
                            ->badge(),
                        TextEntry::make('description')
                            ->prose()
                            ->formatStateUsing(fn (?string $state): string => nl2br(e($state ?? '')))
                            ->html(),
                    ]),
                Section::make('Details')
                    ->columnSpan(1)
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
                            ->label('Updated on Freelancer')
                            ->dateTime(),
                        IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean(),
                        IconEntry::make('is_garbage')
                            ->label('Garbage')
                            ->boolean(),
                    ]),
                Section::make('Client')
                    ->columnSpanFull()
                    ->columns(4)
                    ->visible(fn (Project $record): bool => $record->client !== null)
                    ->schema([
                        TextEntry::make('client.username')
                            ->label('Username'),
                        TextEntry::make('client.country_name')
                            ->label('Country'),
                        TextEntry::make('client.rating')
                            ->label('Rating'),
                        TextEntry::make('client.reviews_count')
                            ->label('Reviews'),
                        IconEntry::make('client.is_payment_verified')
                            ->label('Payment verified')
                            ->boolean(),
                        IconEntry::make('client.is_deposit_made')
                            ->label('Deposit made')
                            ->boolean(),
                        TextEntry::make('client.registered_at')
                            ->label('Registered')
                            ->date(),
                    ]),
            ]);
    }

    public static function formatBudget(Project $record): string
    {
        $sign = $record->currency_sign ?? '';
        $range = $sign.number_format((float) $record->budget_min).' – '.$sign.number_format((float) $record->budget_max);

        return mb_trim($range.' '.$record->currency_code);
    }
}
