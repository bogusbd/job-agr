<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Freelancer\ProjectSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;

#[Signature('freelancer:sync-projects
    {--query= : Full-text search query}
    {--skill=* : Freelancer job (skill) ID, can be repeated}
    {--type=* : Project type: fixed or hourly, can be repeated}
    {--min-price= : Minimum fixed project budget}
    {--max-price= : Maximum fixed project budget}
    {--since= : Only projects submitted within the last N minutes}
    {--param=* : Any extra API parameter as key=value (array values as key[]=value)}
    {--max-pages= : Stop after N pages (100 projects per page)}
    {--without-clients : Do not fetch missing client (owner) details afterwards}')]
#[Description('Import active projects from the Freelancer.com API')]
final class SyncFreelancerProjects extends Command
{
    public function handle(ProjectSyncService $sync): int
    {
        $startedAt = now();
        $parameters = $this->searchParameters();
        $maxPages = $this->option('max-pages') !== null ? (int) $this->option('max-pages') : null;

        try {
            $result = $sync->syncActiveProjects(
                $parameters,
                $maxPages,
                fn (int $page, int $imported, int $total) => $this->line("Page {$page}: {$imported} / {$total}"),
            );
        } catch (RequestException $exception) {
            $this->error('Freelancer API request failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Imported {$result['imported']} of {$result['total']} active projects.");

        if ($parameters === [] && $maxPages === null && $result['complete']) {
            $deactivated = $sync->deactivateProjectsNotSeenSince($startedAt);
            $this->info("Marked {$deactivated} projects as no longer active.");
        }

        if (! $this->option('without-clients')) {
            try {
                $this->info('Fetched '.$sync->syncMissingClients().' missing clients.');
            } catch (RequestException $exception) {
                $this->warn('Could not fetch clients: '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function searchParameters(): array
    {
        $parameters = array_filter([
            'query' => $this->option('query'),
            'jobs' => array_map(intval(...), $this->option('skill')),
            'project_types' => $this->option('type'),
            'min_price' => $this->option('min-price'),
            'max_price' => $this->option('max-price'),
            'from_time' => $this->option('since') !== null ? now()->subMinutes((int) $this->option('since'))->getTimestamp() : null,
        ], fn (mixed $value): bool => filled($value));

        foreach ($this->option('param') as $pair) {
            [$key, $value] = array_pad(explode('=', (string) $pair, 2), 2, '');

            if (str_ends_with($key, '[]')) {
                $parameters[mb_substr($key, 0, -2)][] = $value;
            } else {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }
}
