<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\LocationMode;
use App\Jobs\ScrapeLeadRequest;
use App\Models\Lead;
use App\Models\LeadRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class LeadGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_open_the_lead_pages(): void
    {
        $this->get(route('leads.index'))->assertRedirect(route('login'));
        $this->get(route('leads.create'))->assertRedirect(route('login'));
    }

    public function test_user_can_request_leads_for_multiple_cities(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::leads.create')
            ->set('locationMode', 'cities')
            ->call('next')
            ->set('locations', "Manila\nCebu City\nManila")
            ->call('next')
            ->set('categories', ['Dentist', 'Orthodontist'])
            ->call('submit')
            ->assertHasNoErrors();

        $leadRequest = $user->leadRequests()->sole();

        $this->assertSame(LeadSource::GoogleMaps, $leadRequest->source);
        $this->assertSame(LocationMode::Cities, $leadRequest->location_mode);
        $this->assertSame(['Manila', 'Cebu City'], $leadRequest->locations);
        $this->assertCount(4, $leadRequest->searchTargets());

        Queue::assertPushed(ScrapeLeadRequest::class, fn (ScrapeLeadRequest $job) => $job->leadRequest->is($leadRequest));
    }

    public function test_geofence_radius_in_miles_is_stored_in_kilometres(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::leads.create')
            ->set('locationMode', 'geofence')
            ->call('next')
            ->set('latitude', 14.5995)
            ->set('longitude', 120.9842)
            ->set('unit', 'mi')
            ->set('radius', 10)
            ->call('next')
            ->call('submit')
            ->assertHasNoErrors();

        $leadRequest = $user->leadRequests()->sole();

        $this->assertEqualsWithDelta(16.09, $leadRequest->radius_km, 0.01);
        $this->assertNull($leadRequest->locations);
    }

    public function test_location_is_required_to_leave_step_two(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::leads.create')
            ->call('next')
            ->call('next')
            ->assertHasErrors(['locations'])
            ->assertSet('step', 2);
    }

    public function test_unavailable_sources_cannot_be_selected(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::leads.create')
            ->set('source', 'linkedin')
            ->call('next')
            ->assertHasErrors(['source'])
            ->assertSet('step', 1);
    }

    public function test_facebook_is_selectable_but_cannot_use_a_geofence(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::leads.create')
            ->set('source', 'facebook')
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 2)
            ->set('locationMode', 'geofence')
            ->call('next')
            ->assertHasErrors(['locationMode'])
            ->assertSet('step', 2);
    }

    public function test_switching_to_facebook_resets_a_geofence_selection(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::leads.create')
            ->set('locationMode', 'geofence')
            ->set('source', 'facebook')
            ->assertSet('locationMode', 'city');
    }

    public function test_request_list_only_shows_own_requests(): void
    {
        $user = User::factory()->create();
        $mine = LeadRequest::factory()->for($user)->create(['locations' => ['Makati']]);
        $theirs = LeadRequest::factory()->create(['locations' => ['Quezon City']]);

        Livewire::actingAs($user)
            ->test('pages::leads.index')
            ->assertSee('Makati')
            ->assertDontSee('Quezon City');

        $this->assertNotSame($mine->user_id, $theirs->user_id);
    }

    public function test_user_cannot_view_or_export_another_users_leads(): void
    {
        $leadRequest = LeadRequest::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('leads.show', $leadRequest))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->get(route('leads.export', $leadRequest))
            ->assertForbidden();
    }

    public function test_owner_can_export_leads_to_excel(): void
    {
        $user = User::factory()->create();
        $leadRequest = LeadRequest::factory()->for($user)->completed()->create();
        Lead::factory()->count(3)->for($leadRequest)->create();

        $this->actingAs($user)
            ->get(route('leads.show', $leadRequest))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('leads.export', $leadRequest))
            ->assertOk()
            ->assertDownload();
    }
}
