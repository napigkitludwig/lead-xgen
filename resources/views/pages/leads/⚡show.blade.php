<?php

use App\Models\Lead;
use App\Models\LeadRequest;
use App\Services\GoogleSheetsExporter;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Leads')] class extends Component {
    use WithPagination;

    #[Locked]
    public LeadRequest $leadRequest;

    public function mount(LeadRequest $leadRequest): void
    {
        abort_unless($leadRequest->user_id === auth()->id(), 403);

        $this->leadRequest = $leadRequest;
    }

    public string $sheetEmail = '';

    #[Computed]
    public function sheetsConfigured(): bool
    {
        return app(GoogleSheetsExporter::class)->isConfigured();
    }

    public function exportToGoogleSheets(GoogleSheetsExporter $exporter): void
    {
        $this->validate(['sheetEmail' => ['required', 'email']], [], ['sheetEmail' => __('Google account email')]);

        if (! $exporter->isConfigured()) {
            Flux::toast(variant: 'danger', text: __('Google Sheets is not configured. See the README.'));

            return;
        }

        try {
            $url = $exporter->export($this->leadRequest, $this->sheetEmail);
        } catch (\Throwable $exception) {
            report($exception);
            Flux::toast(variant: 'danger', text: __('Google Sheets export failed: :message', ['message' => $exception->getMessage()]));

            return;
        }

        $this->leadRequest->update(['google_sheet_url' => $url]);

        Flux::modal('google-sheets')->close();
        Flux::toast(variant: 'success', text: __('Exported. The sheet was shared with :email.', ['email' => $this->sheetEmail]));
    }

    /**
     * @return LengthAwarePaginator<int, Lead>
     */
    #[Computed]
    public function leads(): LengthAwarePaginator
    {
        return $this->leadRequest->leads()->completeFirst()->paginate(25);
    }
}; ?>

<section class="w-full space-y-6" @if (! $leadRequest->status->isFinished()) wire:poll.5s @endif>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('leads.index')" wire:navigate>
                {{ __('All requests') }}
            </flux:button>
            <flux:heading size="xl" level="1" class="mt-2">
                {{ $leadRequest->source->label() }} · {{ $leadRequest->locationSummary() }}
            </flux:heading>
            <flux:subheading>
                {{ $leadRequest->created_at->format('M j, Y g:i A') }} · {{ implode(', ', $leadRequest->categories) }}
            </flux:subheading>
        </div>

        <div class="flex items-center gap-3">
            <flux:badge :color="$leadRequest->status->color()">{{ $leadRequest->status->label() }}</flux:badge>
            <flux:button icon="arrow-down-tray" :href="route('leads.export', $leadRequest)" data-test="export-excel-button">
                {{ __('Export to Excel') }}
            </flux:button>
            <flux:modal.trigger name="google-sheets">
                <flux:button icon="table-cells" data-test="export-sheets-button">{{ __('Export to Google Sheets') }}</flux:button>
            </flux:modal.trigger>
            @if ($leadRequest->google_sheet_url)
                <flux:button variant="ghost" icon="arrow-top-right-on-square" :href="$leadRequest->google_sheet_url" target="_blank">
                    {{ __('Open last sheet') }}
                </flux:button>
            @endif
        </div>
    </div>

    <flux:modal name="google-sheets" class="md:w-96">
        <form wire:submit="exportToGoogleSheets" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Export to Google Sheets') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('A new sheet is created and shared with the Google account below.') }}
                </flux:text>
            </div>

            @unless ($this->sheetsConfigured)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.text>{{ __('Google Sheets is not configured yet. Set GOOGLE_SERVICE_ACCOUNT_JSON in .env (see the README).') }}</flux:callout.text>
                </flux:callout>
            @endunless

            <flux:input wire:model="sheetEmail" type="email" :label="__('Google account email')" placeholder="you@gmail.com" required />

            <div class="flex">
                <flux:spacer />
                <flux:button type="submit" variant="primary" :disabled="! $this->sheetsConfigured">{{ __('Export') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    @if ($leadRequest->error)
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.text class="whitespace-pre-line">{{ $leadRequest->error }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:table :paginate="$this->leads">
        <flux:table.columns>
            <flux:table.column>{{ __('Business') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Phone') }}</flux:table.column>
            <flux:table.column>{{ __('First name') }}</flux:table.column>
            <flux:table.column>{{ __('Last name') }}</flux:table.column>
            <flux:table.column>{{ __('Category') }}</flux:table.column>
            <flux:table.column>{{ __('Website') }}</flux:table.column>
            <flux:table.column>{{ __('Location') }}</flux:table.column>
            <flux:table.column>{{ __('Town') }}</flux:table.column>
            <flux:table.column>{{ __('Zip code') }}</flux:table.column>
            <flux:table.column>{{ __('Country') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->leads as $lead)
                <flux:table.row wire:key="lead-{{ $lead->id }}">
                    <flux:table.cell variant="strong">{{ $lead->business_name }}</flux:table.cell>
                    <flux:table.cell>{{ $lead->email }}</flux:table.cell>
                    <flux:table.cell>{{ $lead->phone }}</flux:table.cell>
                    <flux:table.cell>{{ $lead->first_name }}</flux:table.cell>
                    <flux:table.cell>{{ $lead->last_name }}</flux:table.cell>
                    <flux:table.cell>{{ $lead->category }}</flux:table.cell>
                    <flux:table.cell class="max-w-40 truncate">
                        @if ($lead->website)
                            <a href="{{ $lead->website }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $lead->website }}</a>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="max-w-56 truncate">
                        @if ($lead->maps_url && $lead->location)
                            <a href="{{ $lead->maps_url }}" target="_blank" rel="noopener noreferrer" class="underline" title="{{ __('Open in Google Maps') }}">{{ $lead->location }}</a>
                        @else
                            {{ $lead->location }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $lead->town }}</flux:table.cell>
                    <flux:table.cell>{{ $lead->zip_code }}</flux:table.cell>
                    <flux:table.cell>{{ $lead->country }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="11" class="text-center">{{ __('No leads yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
