<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

abstract class NodeScraper
{
    /**
     * Path of the Playwright script that scrapes this source.
     */
    abstract protected function script(): string;

    /**
     * Extra options passed to the script on top of the common ones.
     *
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        return [];
    }

    /**
     * Run the Playwright script for one search.
     *
     * @return list<array{business_name: string, category: ?string, location: ?string, phone: ?string, website: ?string, email: ?string, first_name: ?string, last_name: ?string, source_url: ?string}>
     */
    public function scrape(string $searchUrl, int $maxResults, bool $enrichWebsites = true): array
    {
        $process = new Process(
            [
                config('services.scraper.node'),
                $this->script(),
                json_encode([
                    ...$this->options(),
                    'searchUrl' => $searchUrl,
                    'maxResults' => $maxResults,
                    'enrichWebsites' => $enrichWebsites,
                ], JSON_THROW_ON_ERROR),
            ],
            base_path(),
            null,
            null,
            config('services.scraper.timeout'),
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Scraper failed: '.trim($process->getErrorOutput()));
        }

        $leads = json_decode($process->getOutput(), true);

        if (! is_array($leads)) {
            throw new RuntimeException('Scraper returned invalid output.');
        }

        return $leads;
    }
}
