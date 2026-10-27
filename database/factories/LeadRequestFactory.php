<?php

namespace Database\Factories;

use App\Enums\LeadRequestStatus;
use App\Enums\LeadSource;
use App\Enums\LocationMode;
use App\Models\LeadRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadRequest>
 */
class LeadRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => LeadSource::GoogleMaps,
            'location_mode' => LocationMode::City,
            'locations' => [fake()->city()],
            'categories' => ['Dentist'],
            'max_results' => 20,
            'only_with_contact' => false,
            'status' => LeadRequestStatus::Pending,
        ];
    }

    public function geofence(): static
    {
        return $this->state(fn () => [
            'location_mode' => LocationMode::Geofence,
            'locations' => null,
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'radius_km' => 5,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => LeadRequestStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }
}
