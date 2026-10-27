<?php

namespace Tests\Feature;

use App\Enums\LeadRequestStatus;
use App\Enums\LeadSource;
use App\Jobs\ScrapeLeadRequest;
use App\Models\LeadRequest;
use App\Services\FacebookScraper;
use App\Services\GoogleMapsScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ScrapeLeadRequestJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, ?string>
     */
    private function place(string $name, ?string $phone, string $mapsUrl = 'https://www.google.com/maps/place/x'): array
    {
        return [
            'business_name' => $name,
            'category' => 'Dentist',
            'location' => '1 Main St',
            'phone' => $phone,
            'website' => null,
            'email' => 'hello@example-clinic.test',
            'first_name' => null,
            'last_name' => null,
            'source_url' => $mapsUrl,
        ];
    }

    public function test_it_saves_unique_leads_and_completes_the_request(): void
    {
        $leadRequest = LeadRequest::factory()->create(['categories' => ['Dentist', 'Dental Clinic']]);

        $this->mock(GoogleMapsScraper::class, function (MockInterface $mock) {
            $mock->shouldReceive('scrape')->twice()->andReturn([
                $this->place('Bright Smile', '+63 2 1234 5678'),
                $this->place('Bright Smile Duplicate', '+63 2 1234 5678'),
            ]);
        });

        (new ScrapeLeadRequest($leadRequest))->handle();

        $leadRequest->refresh();

        $this->assertSame(LeadRequestStatus::Completed, $leadRequest->status, (string) $leadRequest->error);
        $this->assertSame(1, $leadRequest->total_leads);
        $this->assertDatabaseHas('leads', ['lead_request_id' => $leadRequest->id, 'business_name' => 'Bright Smile']);
        $this->assertDatabaseMissing('leads', ['business_name' => 'Bright Smile Duplicate']);
    }

    public function test_geofence_drops_places_outside_the_radius(): void
    {
        $leadRequest = LeadRequest::factory()->geofence()->create();
        $inside = 'https://www.google.com/maps/place/a/data=!3d14.6000!4d120.9850';
        $outside = 'https://www.google.com/maps/place/b/data=!3d14.9000!4d121.5000';

        $this->mock(GoogleMapsScraper::class, function (MockInterface $mock) use ($inside, $outside) {
            $mock->shouldReceive('scrape')->once()->andReturn([
                $this->place('Near Clinic', '111', $inside),
                $this->place('Far Clinic', '222', $outside),
            ]);
        });

        (new ScrapeLeadRequest($leadRequest))->handle();

        $this->assertDatabaseHas('leads', ['business_name' => 'Near Clinic']);
        $this->assertDatabaseMissing('leads', ['business_name' => 'Far Clinic']);
    }

    public function test_facebook_requests_use_the_facebook_scraper_and_fill_category_and_location(): void
    {
        $leadRequest = LeadRequest::factory()->create([
            'source' => LeadSource::Facebook,
            'locations' => ['Cebu City'],
            'categories' => ['Orthodontist'],
        ]);

        $this->mock(FacebookScraper::class, function (MockInterface $mock) {
            $mock->shouldReceive('scrape')
                ->once()
                ->withArgs(fn (string $url) => str_starts_with($url, 'https://www.facebook.com/search/pages/?q=Orthodontist%20in%20Cebu%20City'))
                ->andReturn([[...$this->place('Cebu Braces', '999'), 'category' => null, 'location' => null]]);
        });
        $this->mock(GoogleMapsScraper::class)->shouldNotReceive('scrape');

        (new ScrapeLeadRequest($leadRequest))->handle();

        $this->assertDatabaseHas('leads', [
            'business_name' => 'Cebu Braces',
            'category' => 'Orthodontist',
            'location' => 'Cebu City',
        ]);
    }

    public function test_it_stops_searching_once_the_wanted_leads_are_found(): void
    {
        $leadRequest = LeadRequest::factory()->create([
            'max_results' => 2,
            'locations' => ['Manila', 'Cebu City', 'Davao City'],
        ]);

        $this->mock(GoogleMapsScraper::class, function (MockInterface $mock) {
            $mock->shouldReceive('scrape')
                ->once()
                ->with(\Mockery::any(), 2, true, 2, false)
                ->andReturn([
                    $this->place('First', '111'),
                    $this->place('Second', '222'),
                    $this->place('Third', '333'),
                ]);
        });

        (new ScrapeLeadRequest($leadRequest))->handle();

        $leadRequest->refresh();

        $this->assertSame(LeadRequestStatus::Completed, $leadRequest->status);
        $this->assertSame(2, $leadRequest->total_leads);
        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseMissing('leads', ['business_name' => 'Third']);
    }

    public function test_it_only_asks_for_the_leads_still_missing_on_the_next_search(): void
    {
        $leadRequest = LeadRequest::factory()->create([
            'max_results' => 3,
            'locations' => ['Manila', 'Cebu City'],
        ]);

        $this->mock(GoogleMapsScraper::class, function (MockInterface $mock) {
            $mock->shouldReceive('scrape')->once()->with(\Mockery::any(), 3, true, 3, false)
                ->andReturn([$this->place('One', '111')]);
            $mock->shouldReceive('scrape')->once()->with(\Mockery::any(), 2, true, 2, false)
                ->andReturn([$this->place('Two', '222'), $this->place('Three', '333')]);
        });

        (new ScrapeLeadRequest($leadRequest))->handle();

        $this->assertSame(3, $leadRequest->refresh()->total_leads);
    }

    public function test_it_passes_the_contact_requirement_to_the_scraper(): void
    {
        $leadRequest = LeadRequest::factory()->create(['max_results' => 5, 'only_with_contact' => true]);

        $this->mock(GoogleMapsScraper::class, function (MockInterface $mock) {
            $mock->shouldReceive('scrape')->once()->with(\Mockery::any(), 120, true, 5, true)->andReturn([]);
        });

        (new ScrapeLeadRequest($leadRequest))->handle();

        $this->assertSame(LeadRequestStatus::Completed, $leadRequest->refresh()->status);
    }

    public function test_it_saves_town_zip_code_and_country(): void
    {
        $leadRequest = LeadRequest::factory()->create(['locations' => ['Makati, Philippines']]);

        $this->mock(GoogleMapsScraper::class, function (MockInterface $mock) {
            $mock->shouldReceive('scrape')->once()->andReturn([
                [...$this->place('Bright Smile', '111'), 'location' => '1408 Kalayaan Ave, Poblacion, Makati City, 1210 Metro Manila'],
            ]);
        });

        (new ScrapeLeadRequest($leadRequest))->handle();

        $this->assertDatabaseHas('leads', [
            'business_name' => 'Bright Smile',
            'town' => 'Makati City',
            'zip_code' => '1210',
            'country' => 'Philippines',
        ]);
    }

    public function test_it_can_keep_only_leads_with_email_and_phone(): void
    {
        $leadRequest = LeadRequest::factory()->create(['only_with_contact' => true]);

        $this->mock(GoogleMapsScraper::class, function (MockInterface $mock) {
            $mock->shouldReceive('scrape')->once()->andReturn([
                $this->place('Complete Dental', '111'),
                $this->place('No Phone Dental', null),
                [...$this->place('No Email Dental', '222'), 'email' => null],
            ]);
        });

        (new ScrapeLeadRequest($leadRequest))->handle();

        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseHas('leads', ['business_name' => 'Complete Dental']);
    }

    public function test_request_fails_when_every_search_errors(): void
    {
        $leadRequest = LeadRequest::factory()->create();

        $this->mock(GoogleMapsScraper::class, function (MockInterface $mock) {
            $mock->shouldReceive('scrape')->andThrow(new RuntimeException('Blocked by Google'));
        });

        (new ScrapeLeadRequest($leadRequest))->handle();

        $leadRequest->refresh();

        $this->assertSame(LeadRequestStatus::Failed, $leadRequest->status);
        $this->assertStringContainsString('Blocked by Google', $leadRequest->error);
    }
}
