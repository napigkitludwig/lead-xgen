<?php

namespace App\Services;

use App\Exports\LeadsExport;
use App\Models\Lead;
use App\Models\LeadRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Exports a lead request to a new Google Sheet using a service account.
 *
 * The sheet is created in the service account's Drive and shared with the
 * requested Google account, so no per-user Google login is needed.
 */
class GoogleSheetsExporter
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPES = 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive';

    public function isConfigured(): bool
    {
        $path = config('services.google_sheets.credentials');

        return is_string($path) && $path !== '' && is_readable($path);
    }

    /**
     * Create the sheet, fill it with the leads and share it. Returns the sheet URL.
     */
    public function export(LeadRequest $leadRequest, string $shareWithEmail): string
    {
        $token = $this->accessToken();

        $sheet = Http::withToken($token)
            ->post('https://sheets.googleapis.com/v4/spreadsheets', [
                'properties' => ['title' => $this->title($leadRequest)],
            ])
            ->throw()
            ->json();

        Http::withToken($token)
            ->put("https://sheets.googleapis.com/v4/spreadsheets/{$sheet['spreadsheetId']}/values/A1", [
                'values' => $this->rows($leadRequest),
            ])
            ->throw();

        Http::withToken($token)
            ->post("https://www.googleapis.com/drive/v3/files/{$sheet['spreadsheetId']}/permissions?sendNotificationEmail=false", [
                'type' => 'user',
                'role' => 'writer',
                'emailAddress' => $shareWithEmail,
            ])
            ->throw();

        return $sheet['spreadsheetUrl'];
    }

    private function title(LeadRequest $leadRequest): string
    {
        return sprintf('Leads #%d - %s', $leadRequest->id, $leadRequest->created_at->format('Y-m-d H:i'));
    }

    /**
     * @return list<list<?string>>
     */
    private function rows(LeadRequest $leadRequest): array
    {
        $export = new LeadsExport($leadRequest);

        return $leadRequest->leads()
            ->orderBy('id')
            ->get()
            ->map(fn (Lead $lead) => $export->map($lead))
            ->prepend($export->headings())
            ->values()
            ->all();
    }

    private function accessToken(): string
    {
        return Cache::remember('google-sheets-token', 3000, function (): string {
            $credentials = json_decode((string) file_get_contents(config('services.google_sheets.credentials')), true);

            if (! isset($credentials['client_email'], $credentials['private_key'])) {
                throw new RuntimeException('The Google service account file is invalid.');
            }

            $now = time();
            $claims = [
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPES,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ];

            $unsigned = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$this->base64Url(json_encode($claims));

            if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Could not sign the Google access request.');
            }

            return Http::asForm()
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $unsigned.'.'.$this->base64Url($signature),
                ])
                ->throw()
                ->json('access_token');
        });
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
