<?php

namespace App\Services;

use App\Models\User;

class FacebookScraper extends NodeScraper
{
    private ?string $sessionFile = null;

    /**
     * A scraper that browses Facebook as the given user's connected account.
     */
    public function forUser(User $user): static
    {
        $scraper = clone $this;
        $scraper->sessionFile = app(FacebookSession::class)->path($user);

        return $scraper;
    }

    protected function script(): string
    {
        return resource_path('scrapers/facebook.mjs');
    }

    /**
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        return ['storageState' => $this->sessionFile];
    }
}
