<?php

namespace App\Services;

class GoogleMapsScraper extends NodeScraper
{
    protected function script(): string
    {
        return resource_path('scrapers/google-maps.mjs');
    }
}
