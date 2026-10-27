<?php

namespace App\Exports;

use App\Models\Lead;
use App\Models\LeadRequest;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

class LeadsExport implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping
{
    use Exportable;

    /** Column letter of "Location", which links to Google Maps. */
    public const LOCATION_COLUMN = 'H';

    private int $mappedRows = 0;

    /**
     * Google Maps URL per sheet row, filled while rows are mapped.
     *
     * @var array<int, string>
     */
    private array $mapsUrls = [];

    public function __construct(private LeadRequest $leadRequest) {}

    /**
     * @return Builder<Lead>
     */
    public function query(): Builder
    {
        return Lead::query()->whereBelongsTo($this->leadRequest)->completeFirst();
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Business Name',
            'Email',
            'Phone',
            'First Name',
            'Last Name',
            'Business Type/Category',
            'Website',
            'Location',
            'Town',
            'Zip Code',
            'Country',
            'Google Maps Link',
        ];
    }

    /**
     * @param  Lead  $row
     * @return list<?string>
     */
    public function map($row): array
    {
        $this->mappedRows++;

        if ($row->maps_url) {
            $this->mapsUrls[$this->mappedRows + 1] = $row->maps_url;
        }

        return [
            $row->business_name,
            $row->email,
            $row->phone,
            $row->first_name,
            $row->last_name,
            $row->category,
            $row->website,
            $row->location,
            $row->town,
            $row->zip_code,
            $row->country,
            $row->maps_url,
        ];
    }

    /**
     * Make every Location cell a link to its Google Maps page.
     *
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                foreach ($this->mapsUrls as $row => $url) {
                    $cell = self::LOCATION_COLUMN.$row;

                    $sheet->getCell($cell)->getHyperlink()->setUrl($url);
                    $sheet->getStyle($cell)->getFont()->getColor()->setARGB('FF0563C1');
                    $sheet->getStyle($cell)->getFont()->setUnderline(true);
                }
            },
        ];
    }
}
