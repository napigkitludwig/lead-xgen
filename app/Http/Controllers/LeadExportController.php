<?php

namespace App\Http\Controllers;

use App\Exports\LeadsExport;
use App\Models\LeadRequest;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LeadExportController extends Controller
{
    public function __invoke(Request $request, LeadRequest $leadRequest): BinaryFileResponse
    {
        abort_unless($leadRequest->user_id === $request->user()->id, 403);

        $filename = sprintf('leads-%d-%s.xlsx', $leadRequest->id, $leadRequest->created_at->format('Ymd-His'));

        return Excel::download(new LeadsExport($leadRequest), $filename);
    }
}
