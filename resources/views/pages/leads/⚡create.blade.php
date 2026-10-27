<?php

use App\Enums\BusinessCategory;
use App\Enums\LeadSource;
use App\Enums\LocationMode;
use App\Enums\ScrapingMethod;
use App\Jobs\ScrapeLeadRequest;
use App\Jobs\ConnectFacebookAccount;
use App\Services\FacebookSession;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Generate leads')] class extends Component {
    private const MILES_TO_KM = 1.609344;

    private const TOTAL_STEPS = 4;

    public int $step = 1;

    public string $source = 'google_maps';

    public string $scrapingMethod = 'playwright';

    public string $locationMode = 'city';

    public string $locations = '';

    public ?float $latitude = null;

    public ?float $longitude = null;

    public float $radius = 5;

    public string $unit = 'km';

    /** @var list<string> */
    public array $categories = ['Dentist', 'Dental Clinic'];

    public int $maxResults = 20;

    public bool $onlyWithContact = false;

    /** Whether the user's Facebook session is logged in; null until checked. */
    public ?bool $facebookConnected = null;

    public ?string $facebookAccount = null;

    public bool $facebookConnecting = false;

    public ?string $facebookError = null;

    /**
     * @return list<LeadSource>
     */
    #[Computed]
    public function sources(): array
    {
        return LeadSource::cases();
    }

    #[Computed]
    public function totalSteps(): int
    {
        return self::TOTAL_STEPS;
    }

    /**
     * @return list<ScrapingMethod>
     */
    #[Computed]
    public function scrapingMethods(): array
    {
        return ScrapingMethod::cases();
    }

    /**
     * @return list<LocationMode>
     */
    #[Computed]
    public function locationModes(): array
    {
        $source = LeadSource::tryFrom($this->source) ?? LeadSource::GoogleMaps;

        return array_values(array_filter(
            LocationMode::cases(),
            fn (LocationMode $mode) => $mode !== LocationMode::Geofence || $source->supportsGeofence(),
        ));
    }

    /**
     * @return list<BusinessCategory>
     */
    #[Computed]
    public function businessCategories(): array
    {
        return BusinessCategory::cases();
    }

    /**
     * Radius presets for the selected unit.
     *
     * @return list<int>
     */
    #[Computed]
    public function radiusPresets(): array
    {
        return $this->unit === 'mi' ? [1, 2, 5, 10, 15, 30] : [1, 2, 5, 10, 25, 50];
    }

    public function next(): void
    {
        $this->validateStep();

        $this->step = min($this->step + 1, self::TOTAL_STEPS);
    }

    public function back(): void
    {
        $this->step = max($this->step - 1, 1);
    }

    public function updatedLocationMode(): void
    {
        $this->resetErrorBag();
    }

    public function updatedSource(): void
    {
        if ($this->locationMode === LocationMode::Geofence->value && ! (LeadSource::tryFrom($this->source)?->supportsGeofence() ?? false)) {
            $this->locationMode = LocationMode::City->value;
        }

        if ($this->source === LeadSource::Facebook->value) {
            $this->checkFacebook();
        }
    }

    /**
     * Look for a logged-in Facebook session saved for the current user.
     */
    public function checkFacebook(bool $fresh = false): void
    {
        $this->facebookError = null;

        try {
            $status = app(FacebookSession::class)->status(Auth::user(), $fresh);
        } catch (\Throwable $exception) {
            $this->facebookConnected = false;
            $this->facebookAccount = null;
            $this->facebookError = __('Could not check Facebook: :message', ['message' => $exception->getMessage()]);

            return;
        }

        $this->facebookConnected = $status['connected'];
        $this->facebookAccount = $status['name'];
    }

    /**
     * Open a Facebook login window (on the machine running the app) to pick the account.
     */
    public function connectFacebook(): void
    {
        $session = app(FacebookSession::class);

        $session->setConnectionState(Auth::user(), 'waiting');
        ConnectFacebookAccount::dispatch(Auth::user());

        $this->facebookConnecting = true;
        $this->facebookError = null;
    }

    public function pollFacebookConnection(): void
    {
        $session = app(FacebookSession::class);
        $state = $session->connectionState(Auth::user());

        if ($state === 'done') {
            $this->facebookConnecting = false;
            $session->clearConnectionState(Auth::user());
            $this->checkFacebook();
        } elseif ($state === null || str_starts_with($state, 'failed')) {
            $this->facebookConnecting = false;
            $this->facebookError = $state === null
                ? __('The Facebook login timed out. Try again.')
                : __('Facebook login failed: :message', ['message' => trim(substr($state, 7))]);
            $session->clearConnectionState(Auth::user());
        }
    }

    public function cancelFacebookConnect(): void
    {
        app(FacebookSession::class)->clearConnectionState(Auth::user());
        $this->facebookConnecting = false;
    }

    public function disconnectFacebook(): void
    {
        app(FacebookSession::class)->disconnect(Auth::user());

        $this->facebookConnected = false;
        $this->facebookAccount = null;
        $this->facebookConnecting = false;
    }

    public function submit(): void
    {
        foreach (range(1, self::TOTAL_STEPS) as $step) {
            $this->validateStep($step);
        }

        $mode = LocationMode::from($this->locationMode);

        $leadRequest = Auth::user()->leadRequests()->create([
            'source' => $this->source,
            'scraping_method' => $this->scrapingMethod,
            'location_mode' => $mode,
            'locations' => $mode->usesPlaceNames() ? $this->parsedLocations() : null,
            'latitude' => $mode === LocationMode::Geofence ? $this->latitude : null,
            'longitude' => $mode === LocationMode::Geofence ? $this->longitude : null,
            'radius_km' => $mode === LocationMode::Geofence ? $this->radiusInKilometers() : null,
            'categories' => $this->categories,
            'max_results' => $this->maxResults,
            'only_with_contact' => $this->onlyWithContact,
        ]);

        ScrapeLeadRequest::dispatch($leadRequest);

        Flux::toast(variant: 'success', text: __('Lead request started. Leads will appear as they are found.'));

        $this->redirectRoute('leads.show', $leadRequest, navigate: true);
    }

    /**
     * @return list<string>
     */
    private function parsedLocations(): array
    {
        $names = collect(preg_split('/\R/', $this->locations) ?: [])
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique()
            ->values();

        return ($this->locationModeEnum()->allowsMultiple() ? $names : $names->take(1))->all();
    }

    private function locationModeEnum(): LocationMode
    {
        return LocationMode::tryFrom($this->locationMode) ?? LocationMode::City;
    }

    private function radiusInKilometers(): float
    {
        return round($this->unit === 'mi' ? $this->radius * self::MILES_TO_KM : $this->radius, 2);
    }

    private function validateStep(?int $step = null): void
    {
        match ($step ?? $this->step) {
            1 => $this->validateSource(),
            2 => $this->validate([
                'scrapingMethod' => ['required', Rule::enum(ScrapingMethod::class)],
            ], ['scrapingMethod.enum' => __('Choose Playwright or Puppeteer.')]),
            3 => $this->validateLocation(),
            4 => $this->validate([
                'categories' => ['required', 'array', 'min:1'],
                'categories.*' => [Rule::enum(BusinessCategory::class)],
                'maxResults' => ['required', 'integer', 'min:1', 'max:1000'],
            ], ['categories.min' => __('Select at least one business category.')]),
        };
    }

    private function validateSource(): void
    {
        $this->validate([
            'source' => ['required', Rule::enum(LeadSource::class)->only([LeadSource::GoogleMaps, LeadSource::Facebook])],
        ], ['source.enum' => __('This source is not available yet.')]);

        if ($this->source === LeadSource::Facebook->value) {
            $this->checkFacebook();

            if (! $this->facebookConnected) {
                throw ValidationException::withMessages([
                    'source' => __('Log in to a Facebook account first, then continue.'),
                ]);
            }
        }
    }

    private function validateLocation(): void
    {
        $this->validate(['locationMode' => ['required', Rule::enum(LocationMode::class)]]);

        if ($this->locationModeEnum() === LocationMode::Geofence && ! LeadSource::from($this->source)->supportsGeofence()) {
            throw ValidationException::withMessages(['locationMode' => __('Geofence is only available for Google Maps.')]);
        }

        if ($this->locationModeEnum() === LocationMode::Geofence) {
            $this->validate([
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
                'unit' => ['required', 'in:km,mi'],
                'radius' => ['required', 'numeric', 'min:0.5', 'max:'.($this->unit === 'mi' ? 50 : 80)],
            ], [
                'latitude.required' => __('Enter the latitude of the centre point, or use your location.'),
                'longitude.required' => __('Enter the longitude of the centre point, or use your location.'),
            ]);

            return;
        }

        $this->validate(
            ['locations' => ['required', 'string']],
            ['locations.required' => __('Enter at least one place.')],
        );

        if ($this->parsedLocations() === []) {
            throw ValidationException::withMessages(['locations' => __('Enter at least one place.')]);
        }
    }
}; ?>

<section class="mx-auto w-full max-w-3xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Generate leads') }}</flux:heading>
        <flux:subheading>{{ __('Step :step of :total', ['step' => $step, 'total' => $this->totalSteps]) }}</flux:subheading>
    </div>

    <flux:progress :value="($step / $this->totalSteps) * 100" />

    <form wire:submit="submit" class="space-y-6">
        @if ($step === 1)
            <flux:radio.group wire:model.live="source" :label="__('Where should the leads come from?')">
                @foreach ($this->sources as $leadSource)
                    <flux:radio
                        wire:key="source-{{ $leadSource->value }}"
                        :value="$leadSource->value"
                        :label="$leadSource->label().($leadSource->isAvailable() ? '' : ' — '.__('coming soon'))"
                        :disabled="! $leadSource->isAvailable()"
                    />
                @endforeach
            </flux:radio.group>
            <flux:error name="source" />

            @if ($source === 'facebook')
                <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" @if ($facebookConnecting) wire:poll.3s="pollFacebookConnection" @endif>
                    <div wire:loading wire:target="source,checkFacebook" class="text-sm text-zinc-500">
                        {{ __('Checking for a logged-in Facebook session…') }}
                    </div>

                    <div wire:loading.remove wire:target="source,checkFacebook" class="space-y-3">
                        @if ($facebookConnecting)
                            <flux:callout icon="arrow-top-right-on-square">
                                <flux:callout.text>
                                    {{ __('A Facebook login window was opened on the computer running this app. Log in there; this page updates by itself.') }}
                                </flux:callout.text>
                            </flux:callout>
                            <flux:button type="button" size="sm" wire:click="cancelFacebookConnect">{{ __('Cancel') }}</flux:button>
                        @elseif ($facebookConnected)
                            <flux:callout variant="success" icon="check-circle">
                                <flux:callout.text>
                                    {{ $facebookAccount ? __('Logged in to Facebook as :name. This account will be used.', ['name' => $facebookAccount]) : __('A logged-in Facebook account was found and will be used.') }}
                                </flux:callout.text>
                            </flux:callout>
                            <div class="flex flex-wrap gap-2">
                                <flux:button type="button" size="sm" wire:click="connectFacebook">{{ __('Use a different account') }}</flux:button>
                                <flux:button type="button" size="sm" variant="ghost" wire:click="disconnectFacebook">{{ __('Disconnect') }}</flux:button>
                            </div>
                        @else
                            <flux:callout variant="warning" icon="exclamation-triangle">
                                <flux:callout.text>
                                    {{ __('No logged-in Facebook session was found. Log in to the Facebook account you want to use.') }}
                                </flux:callout.text>
                            </flux:callout>
                            <flux:button type="button" size="sm" variant="primary" icon="arrow-top-right-on-square" wire:click="connectFacebook" data-test="connect-facebook-button">
                                {{ __('Log in to Facebook') }}
                            </flux:button>
                        @endif

                        @if ($facebookError)
                            <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $facebookError }}</flux:text>
                        @endif
                    </div>
                </div>
            @endif

            <flux:callout icon="information-circle">
                <flux:callout.text>
                    {{ __('Google Maps is the most reliable source for dental clinics. Facebook uses your own Facebook login, saved for your user only, and does not support geofence searches. LinkedIn restricts automated access, so it is not enabled.') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        @if ($step === 2)
            <flux:radio.group wire:model.live="scrapingMethod" :label="__('Scraping method')">
                @foreach ($this->scrapingMethods as $method)
                    <flux:radio
                        wire:key="method-{{ $method->value }}"
                        :value="$method->value"
                        :label="$method->label()"
                        :description="$method->description()"
                    />
                @endforeach
            </flux:radio.group>
            <flux:error name="scrapingMethod" />

            <flux:callout icon="information-circle">
                <flux:callout.text>
                    {{ __('The method you choose is the one used to scrape this request. Both give the same lead data; use the other one if a site works better with it.') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        @if ($step === 3)
            <flux:radio.group wire:model.live="locationMode" :label="__('How would you like to get the leads?')">
                @foreach ($this->locationModes as $mode)
                    <flux:radio wire:key="mode-{{ $mode->value }}" :value="$mode->value" :label="$mode->label()" />
                @endforeach
            </flux:radio.group>
            <flux:error name="locationMode" />

            @if ($locationMode === 'geofence')
                <div class="space-y-4" x-data>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="latitude" type="number" step="any" :label="__('Latitude')" placeholder="14.5995" />
                        <flux:input wire:model="longitude" type="number" step="any" :label="__('Longitude')" placeholder="120.9842" />
                    </div>

                    <flux:button
                        type="button"
                        size="sm"
                        icon="map-pin"
                        x-on:click="navigator.geolocation.getCurrentPosition(
                            (p) => { $wire.latitude = +p.coords.latitude.toFixed(6); $wire.longitude = +p.coords.longitude.toFixed(6); },
                            () => alert('Could not read your location.')
                        )"
                    >
                        {{ __('Use my location') }}
                    </flux:button>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="radius" type="number" step="0.5" min="0.5" :label="__('Radius')" />

                        <flux:select wire:model.live="unit" :label="__('Unit')">
                            <flux:select.option value="km">{{ __('Kilometres (km)') }}</flux:select.option>
                            <flux:select.option value="mi">{{ __('Miles (mi)') }}</flux:select.option>
                        </flux:select>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->radiusPresets as $preset)
                            <flux:button type="button" size="sm" wire:key="preset-{{ $preset }}" wire:click="$set('radius', {{ $preset }})">
                                {{ $preset }} {{ $unit }}
                            </flux:button>
                        @endforeach
                    </div>

                    <flux:error name="latitude" />
                    <flux:error name="longitude" />
                    <flux:error name="radius" />
                </div>
            @elseif (in_array($locationMode, ['cities', 'countries'], true))
                <flux:textarea
                    wire:model="locations"
                    rows="5"
                    :label="$locationMode === 'cities' ? __('Towns / cities (one per line)') : __('Countries (one per line)')"
                    :placeholder="$locationMode === 'cities' ? 'Manila, Philippines' : 'Philippines'"
                />
                <flux:error name="locations" />
            @else
                <flux:input
                    wire:model="locations"
                    :label="$locationMode === 'city' ? __('Town / city') : __('Country')"
                    :placeholder="$locationMode === 'city' ? 'Makati, Philippines' : 'Philippines'"
                />
                <flux:error name="locations" />
            @endif

            @if (in_array($locationMode, ['country', 'countries'], true))
                <flux:callout icon="light-bulb">
                    <flux:callout.text>
                        {{ __('Google Maps returns at most about 120 results per search. For whole countries, listing the main cities gives far more complete results.') }}
                    </flux:callout.text>
                </flux:callout>
            @endif
        @endif

        @if ($step === 4)
            <flux:checkbox.group wire:model="categories" :label="__('Business categories')">
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($this->businessCategories as $category)
                        <flux:checkbox
                            wire:key="category-{{ $category->value }}"
                            :value="$category->value"
                            :label="$category->value.($category->isRecommended() ? ' ★' : '')"
                        />
                    @endforeach
                </div>
            </flux:checkbox.group>
            <flux:text size="sm">{{ __('★ = recommended additions') }}</flux:text>
            <flux:error name="categories" />

            <flux:input
                wire:model="maxResults"
                type="number"
                min="1"
                max="1000"
                :label="__('Number of leads needed')"
                :description="__('The search stops as soon as this many leads are found, so it does not keep running in the background.')"
            />
            <flux:error name="maxResults" />

            <flux:checkbox
                wire:model="onlyWithContact"
                :label="__('Only count leads that have both an email and a phone number')"
                :description="__('Leads with both are always listed first. Turn this on to skip the rest, so the leads you asked for all have an email and a phone.')"
            />
        @endif

        <div class="flex justify-between">
            @if ($step > 1)
                <flux:button type="button" wire:click="back">{{ __('Back') }}</flux:button>
            @else
                <span></span>
            @endif

            @if ($step < $this->totalSteps)
                <flux:button type="button" variant="primary" wire:click="next">{{ __('Next') }}</flux:button>
            @else
                <flux:button type="submit" variant="primary" icon="sparkles" data-test="generate-leads-button">
                    {{ __('Generate leads') }}
                </flux:button>
            @endif
        </div>
    </form>
</section>
