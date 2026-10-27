<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\LeadRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_request_id' => LeadRequest::factory(),
            'business_name' => fake()->company().' Dental Clinic',
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'category' => 'Dentist',
            'website' => fake()->url(),
            'location' => fake()->address(),
            'source_url' => fake()->url(),
        ];
    }
}
