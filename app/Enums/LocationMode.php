<?php

namespace App\Enums;

enum LocationMode: string
{
    case City = 'city';
    case Cities = 'cities';
    case Country = 'country';
    case Countries = 'countries';
    case Geofence = 'geofence';

    public function label(): string
    {
        return match ($this) {
            self::City => 'Specific town / city',
            self::Cities => 'Multiple towns / cities',
            self::Country => 'Specific country',
            self::Countries => 'Multiple countries',
            self::Geofence => 'Geofence (radius from a point)',
        };
    }

    public function allowsMultiple(): bool
    {
        return in_array($this, [self::Cities, self::Countries], true);
    }

    public function usesPlaceNames(): bool
    {
        return $this !== self::Geofence;
    }
}
