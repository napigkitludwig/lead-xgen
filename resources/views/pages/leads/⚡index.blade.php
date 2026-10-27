<?php

use App\Models\LeadRequest;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Lead requests')] class extends Component {
    use WithPagination;

    /**
     * @return LengthAwarePaginator<int, LeadRequest>
     */
    #[Computed]
    public function leadRequests(): LengthAwarePaginator
    {
        return Auth::user()->leadRequests()->latest()->paginate(10);
    }

    public function hasUnfinishedRequests(): bool
    {
        return $this->leadRequests->contains(fn (LeadRequest $request) => ! $request->status->isFinished());
    }
}; ?>

<section class="w-full space-y-6" @if ($this->hasUnfinishedRequests()) wire:poll.5s @endif>
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Lead requests') }}</flux:heading>
            <flux:subheading>{{ __('Every generation is saved as its own group of leads.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('leads.create')" wire:navigate>
            {{ __('Generate leads') }}
        </flux:button>
    </div>

    <flux:table :paginate="$this->leadRequests">
        <flux:table.columns>
            <flux:table.column>{{ __('Requested') }}</flux:table.column>
            <flux:table.column>{{ __('Source') }}</flux:table.column>
            <flux:table.column>{{ __('Location') }}</flux:table.column>
            <flux:table.column>{{ __('Categories') }}</flux:table.column>
            <flux:table.column>{{ __('Leads') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column />
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->leadRequests as $leadRequest)
                <flux:table.row wire:key="request-{{ $leadRequest->id }}">
                    <flux:table.cell>{{ $leadRequest->created_at->format('M j, Y g:i A') }}</flux:table.cell>
                    <flux:table.cell>{{ $leadRequest->source->label() }}</flux:table.cell>
                    <flux:table.cell class="max-w-56 truncate">{{ $leadRequest->locationSummary() }}</flux:table.cell>
                    <flux:table.cell class="max-w-56 truncate">{{ implode(', ', $leadRequest->categories) }}</flux:table.cell>
                    <flux:table.cell>{{ $leadRequest->total_leads }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$leadRequest->status->color()">{{ $leadRequest->status->label() }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:button size="sm" :href="route('leads.show', $leadRequest)" wire:navigate>{{ __('View') }}</flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center">{{ __('No lead requests yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
