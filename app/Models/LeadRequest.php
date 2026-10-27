<?php

namespace App\Models;

use App\Enums\LeadRequestStatus;
use App\Enums\LeadSource;
use App\Enums\LocationMode;
use Database\Factories\LeadRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'source',
    'location_mode',
    'locations',
    'latitude',
    'longitude',
    'radius_km',
    'categories',
    'max_results',
    'status',
    'total_leads',
    'error',
    'started_at',
    'completed_at',
    'google_sheet_url',
])]
class LeadRequest extends Model
{
    /** @use HasFactory<LeadRequestFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'location_mode' => LocationMode::class,
            'status' => LeadRequestStatus::class,
            'locations' => 'array',
            'categories' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_km' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Short human readable summary of where the leads are searched.
     */
    public function locationSummary(): string
    {
        if ($this->location_mode === LocationMode::Geofence) {
            return sprintf('%s km around %.4f, %.4f', (float) $this->radius_km, $this->latitude, $this->longitude);
        }

        return implode(', ', $this->locations ?? []);
    }

    /**
     * Every Google Maps search this request expands into: one per category and location.
     *
     * @return list<array{query: string, url: string, category: string, location: ?string}>
     */
    public function searchTargets(): array
    {
        $targets = [];

        foreach ($this->categories as $category) {
            if ($this->location_mode === LocationMode::Geofence) {
                $targets[] = [
                    'query' => $category,
                    'category' => $category,
                    'location' => null,
                    'url' => sprintf(
                        'https://www.google.com/maps/search/%s/@%.7f,%.7f,%dz?hl=en',
                        rawurlencode($category),
                        $this->latitude,
                        $this->longitude,
                        $this->geofenceZoom(),
                    ),
                ];

                continue;
            }

            foreach ($this->locations as $location) {
                $query = "{$category} in {$location}";

                $targets[] = [
                    'query' => $query,
                    'category' => $category,
                    'location' => $location,
                    'url' => $this->source === LeadSource::Facebook
                        ? 'https://www.facebook.com/search/pages/?q='.rawurlencode($query)
                        : 'https://www.google.com/maps/search/'.rawurlencode($query).'?hl=en',
                ];
            }
        }

        return $targets;
    }

    /**
     * Map zoom level whose viewport roughly covers the geofence diameter.
     */
    private function geofenceZoom(): int
    {
        $metersPerPixel = max(($this->radius_km * 2 * 1000) / 1000, 1);
        $zoom = log(156543.03 * cos(deg2rad($this->latitude)) / $metersPerPixel, 2);

        return (int) max(8, min(18, round($zoom)));
    }
}
