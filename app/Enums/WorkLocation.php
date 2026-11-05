<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where the work is done: remotely, or on-site at the location given by the client ("local" jobs on Freelancer.com).
 */
enum WorkLocation: string implements HasLabel
{
    case Remote = 'remote';
    case OnSite = 'on_site';

    public function getLabel(): string
    {
        return match ($this) {
            self::Remote => 'Remote only',
            self::OnSite => 'On-site only',
        };
    }

    public function isLocal(): bool
    {
        return $this === self::OnSite;
    }
}
