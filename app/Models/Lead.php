<?php

namespace App\Models;

use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'lead_request_id',
    'business_name',
    'email',
    'phone',
    'first_name',
    'last_name',
    'category',
    'website',
    'location',
    'town',
    'zip_code',
    'country',
    'source_url',
])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    /**
     * Leads with both an email and a phone number first, then by discovery order.
     *
     * @param  Builder<Lead>  $query
     */
    #[Scope]
    protected function completeFirst(Builder $query): void
    {
        $query->orderByRaw("(email IS NOT NULL AND email <> '' AND phone IS NOT NULL AND phone <> '') DESC")
            ->orderBy('id');
    }

    /**
     * Where the lead can be opened in Google Maps: the place page itself when scraped
     * from Google Maps, otherwise a search for its name and address.
     *
     * @return Attribute<?string, never>
     */
    protected function mapsUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->source_url && str_contains($this->source_url, 'google.com/maps/place')) {
                return $this->source_url;
            }

            if (! $this->location) {
                return null;
            }

            return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode("{$this->business_name}, {$this->location}");
        });
    }

    /**
     * @return BelongsTo<LeadRequest, $this>
     */
    public function leadRequest(): BelongsTo
    {
        return $this->belongsTo(LeadRequest::class);
    }
}
