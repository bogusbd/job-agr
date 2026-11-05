<?php

declare(strict_types=1);

use App\Models\Project;

it('builds a flag emoji from the client country code', function (?string $code, ?string $flag) {
    $project = new Project(['client_country_code' => $code]);

    expect($project->clientCountryFlag())->toBe($flag);
})->with([
    'lowercase code' => ['us', '🇺🇸'],
    'uppercase code' => ['GB', '🇬🇧'],
    'missing code' => [null, null],
    'invalid code' => ['usa', null],
]);
