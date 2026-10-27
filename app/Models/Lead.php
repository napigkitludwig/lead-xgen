<?php

namespace App\Models;

use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
    'source_url',
])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<LeadRequest, $this>
     */
    public function leadRequest(): BelongsTo
    {
        return $this->belongsTo(LeadRequest::class);
    }
}
