<?php

namespace App\Services;

class FacebookScraper extends NodeScraper
{
    protected function script(): string
    {
        return resource_path('scrapers/facebook.mjs');
    }

    /**
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        return ['storageState' => config('services.facebook.session')];
    }
}
