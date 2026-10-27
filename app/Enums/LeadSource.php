<?php

namespace App\Enums;

enum LeadSource: string
{
    case GoogleMaps = 'google_maps';
    case LinkedIn = 'linkedin';
    case Facebook = 'facebook';

    public function label(): string
    {
        return match ($this) {
            self::GoogleMaps => 'Google Maps',
            self::LinkedIn => 'LinkedIn',
            self::Facebook => 'Facebook',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::GoogleMaps => 'map-pin',
            self::LinkedIn => 'briefcase',
            self::Facebook => 'users',
        };
    }

    /**
     * Only sources with a working scraper can be selected.
     */
    public function isAvailable(): bool
    {
        return $this !== self::LinkedIn;
    }

    /**
     * Whether leads can be searched by a radius around a point.
     */
    public function supportsGeofence(): bool
    {
        return $this === self::GoogleMaps;
    }
}
