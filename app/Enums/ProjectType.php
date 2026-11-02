<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProjectType: string implements HasColor, HasLabel
{
    case Fixed = 'fixed';
    case Hourly = 'hourly';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed',
            self::Hourly => 'Hourly',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Fixed => 'info',
            self::Hourly => 'warning',
        };
    }
}
