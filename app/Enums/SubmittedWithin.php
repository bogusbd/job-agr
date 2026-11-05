<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;
use Filament\Support\Contracts\HasLabel;

/**
 * Rolling freshness windows for a project's submission time, from the "hottest" offers
 * (🔥 just posted) through "fresh" ones (⚡ still relevant) to those that are "still open" (🕒).
 */
enum SubmittedWithin: string implements HasLabel
{
    case Last30Minutes = '30m';
    case LastHour = '1h';
    case Last3Hours = '3h';
    case Last6Hours = '6h';
    case Last12Hours = '12h';
    case Last24Hours = '24h';
    case Last3Days = '3d';
    case Last7Days = '7d';

    public function getLabel(): string
    {
        return match ($this) {
            self::Last30Minutes => '🔥 Last 30 minutes',
            self::LastHour => '🔥 Last hour',
            self::Last3Hours => '⚡ Last 3 hours',
            self::Last6Hours => '⚡ Last 6 hours',
            self::Last12Hours => '⚡ Last 12 hours',
            self::Last24Hours => '🕒 Last 24 hours',
            self::Last3Days => '🕒 Last 3 days',
            self::Last7Days => '🕒 Last 7 days',
        };
    }

    public function minutes(): int
    {
        return match ($this) {
            self::Last30Minutes => 30,
            self::LastHour => 60,
            self::Last3Hours => 3 * 60,
            self::Last6Hours => 6 * 60,
            self::Last12Hours => 12 * 60,
            self::Last24Hours => 24 * 60,
            self::Last3Days => 3 * 24 * 60,
            self::Last7Days => 7 * 24 * 60,
        };
    }

    /**
     * The earliest submission time that still falls inside the window.
     */
    public function since(): CarbonImmutable
    {
        return CarbonImmutable::now()->subMinutes($this->minutes());
    }
}
