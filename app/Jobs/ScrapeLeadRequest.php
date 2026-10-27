<?php

namespace App\Jobs;

use App\Enums\LeadRequestStatus;
use App\Enums\LeadSource;
use App\Enums\LocationMode;
use App\Models\LeadRequest;
use App\Services\FacebookScraper;
use App\Services\GoogleMapsScraper;
use App\Support\AddressParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ScrapeLeadRequest implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    /** Google Maps shows about this many results per search. */
    private const MAX_PER_SEARCH = 120;

    public function __construct(public LeadRequest $leadRequest) {}

    public function handle(): void
    {
        $scraper = match ($this->leadRequest->source) {
            LeadSource::Facebook => app(FacebookScraper::class)->forUser($this->leadRequest->user),
            default => app(GoogleMapsScraper::class),
        };

        $this->leadRequest->update([
            'status' => LeadRequestStatus::Running,
            'started_at' => now(),
            'error' => null,
        ]);

        $seen = [];
        $failures = [];
        $wanted = $this->leadRequest->max_results;
        $onlyWithContact = (bool) $this->leadRequest->only_with_contact;
        $isGeofence = $this->leadRequest->location_mode === LocationMode::Geofence;

        foreach ($this->leadRequest->searchTargets() as $target) {
            $remaining = $wanted - count($seen);

            if ($remaining <= 0) {
                break;
            }

            try {
                $found = $scraper->scrape(
                    $target['url'],
                    $onlyWithContact ? self::MAX_PER_SEARCH : min(self::MAX_PER_SEARCH, $remaining),
                    true,
                    $isGeofence ? null : $remaining,
                    $onlyWithContact,
                );
            } catch (Throwable $exception) {
                $failures[] = "{$target['query']}: {$exception->getMessage()}";

                continue;
            }

            foreach ($found as $lead) {
                if (! $this->isInsideGeofence($lead['source_url'] ?? null)) {
                    continue;
                }

                if ($this->leadRequest->only_with_contact && (empty($lead['email']) || empty($lead['phone']))) {
                    continue;
                }

                $lead['category'] ??= $target['category'];
                $address = AddressParser::parse($lead['location'] ?? null, $target['location']);
                $lead['location'] ??= $target['location'];

                $key = $this->duplicateKey($lead);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $this->leadRequest->leads()->create([
                    'business_name' => $lead['business_name'],
                    'email' => $lead['email'] ?? null,
                    'phone' => $lead['phone'] ?? null,
                    'first_name' => $lead['first_name'] ?? null,
                    'last_name' => $lead['last_name'] ?? null,
                    'category' => $lead['category'] ?? null,
                    'website' => $lead['website'] ?? null,
                    'location' => $lead['location'] ?? null,
                    ...$address,
                    'source_url' => $lead['source_url'] ?? null,
                ]);

                if (count($seen) >= $wanted) {
                    break;
                }
            }

            $this->leadRequest->update(['total_leads' => count($seen)]);
        }

        $this->leadRequest->update([
            'status' => $seen === [] && $failures !== [] ? LeadRequestStatus::Failed : LeadRequestStatus::Completed,
            'total_leads' => count($seen),
            'error' => $failures === [] ? null : implode("\n", $failures),
            'completed_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->leadRequest->update([
            'status' => LeadRequestStatus::Failed,
            'error' => $exception->getMessage(),
            'completed_at' => now(),
        ]);
    }

    /**
     * Same phone, website host, or name and address means the same business.
     *
     * @param  array<string, mixed>  $lead
     */
    private function duplicateKey(array $lead): string
    {
        if (! empty($lead['phone'])) {
            return 'phone:'.preg_replace('/\D+/', '', $lead['phone']);
        }

        if (! empty($lead['website'])) {
            return 'site:'.preg_replace('/^www\./', '', strtolower((string) parse_url($lead['website'], PHP_URL_HOST)));
        }

        return 'name:'.strtolower($lead['business_name'].'|'.($lead['location'] ?? ''));
    }

    /**
     * Google Maps shows results beyond the circle, so drop places outside the radius.
     */
    private function isInsideGeofence(?string $sourceUrl): bool
    {
        if ($this->leadRequest->location_mode !== LocationMode::Geofence) {
            return true;
        }

        if (! $sourceUrl || ! preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $sourceUrl, $match)) {
            return true;
        }

        $earthRadiusKm = 6371;
        $latFrom = deg2rad($this->leadRequest->latitude);
        $latTo = deg2rad((float) $match[1]);
        $deltaLat = $latTo - $latFrom;
        $deltaLng = deg2rad((float) $match[2] - $this->leadRequest->longitude);

        $a = sin($deltaLat / 2) ** 2 + cos($latFrom) * cos($latTo) * sin($deltaLng / 2) ** 2;

        return 2 * $earthRadiusKm * asin(sqrt($a)) <= $this->leadRequest->radius_km;
    }
}
