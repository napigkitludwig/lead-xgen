<?php

namespace App\Exports;

use App\Models\Lead;
use App\Models\LeadRequest;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LeadsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    use Exportable;

    public function __construct(private LeadRequest $leadRequest) {}

    /**
     * @return Builder<Lead>
     */
    public function query(): Builder
    {
        return Lead::query()->whereBelongsTo($this->leadRequest)->orderBy('id');
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Business Name', 'Email', 'Phone', 'First Name', 'Last Name', 'Business Type/Category', 'Website', 'Location'];
    }

    /**
     * @param  Lead  $row
     * @return list<?string>
     */
    public function map($row): array
    {
        return [
            $row->business_name,
            $row->email,
            $row->phone,
            $row->first_name,
            $row->last_name,
            $row->category,
            $row->website,
            $row->location,
        ];
    }
}
