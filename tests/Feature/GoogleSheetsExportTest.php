<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class GoogleSheetsExportTest extends TestCase
{
    use RefreshDatabase;

    private string $credentialsPath;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('google-sheets-token');

        $opensslConfig = tempnam(sys_get_temp_dir(), 'cnf');
        file_put_contents($opensslConfig, "[req]\ndistinguished_name=dn\n[dn]\n");

        $options = ['config' => $opensslConfig, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $privateKey, null, $options);
        @unlink($opensslConfig);

        $this->credentialsPath = tempnam(sys_get_temp_dir(), 'gsa');
        file_put_contents($this->credentialsPath, json_encode([
            'client_email' => 'bot@project.iam.gserviceaccount.com',
            'private_key' => $privateKey,
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->credentialsPath);
        Cache::forget('google-sheets-token');

        parent::tearDown();
    }

    public function test_it_creates_fills_and_shares_a_sheet(): void
    {
        config(['services.google_sheets.credentials' => $this->credentialsPath]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'sheets.googleapis.com/v4/spreadsheets' => Http::response([
                'spreadsheetId' => 'abc123',
                'spreadsheetUrl' => 'https://docs.google.com/spreadsheets/d/abc123/edit',
            ]),
            'sheets.googleapis.com/v4/spreadsheets/abc123/values/A1' => Http::response([]),
            'www.googleapis.com/drive/v3/files/abc123/permissions*' => Http::response([]),
        ]);

        $user = User::factory()->create();
        $leadRequest = LeadRequest::factory()->for($user)->completed()->create();
        Lead::factory()->count(2)->for($leadRequest)->create();

        Livewire::actingAs($user)
            ->test('pages::leads.show', ['leadRequest' => $leadRequest])
            ->set('sheetEmail', 'owner@gmail.com')
            ->call('exportToGoogleSheets')
            ->assertHasNoErrors();

        $this->assertSame('https://docs.google.com/spreadsheets/d/abc123/edit', $leadRequest->fresh()->google_sheet_url);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/values/A1')
            && count($request['values']) === 3
            && $request['values'][0][0] === 'Business Name');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/permissions')
            && $request['emailAddress'] === 'owner@gmail.com'
            && $request['role'] === 'writer');
    }

    public function test_it_requires_a_valid_email(): void
    {
        $user = User::factory()->create();
        $leadRequest = LeadRequest::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test('pages::leads.show', ['leadRequest' => $leadRequest])
            ->set('sheetEmail', 'not-an-email')
            ->call('exportToGoogleSheets')
            ->assertHasErrors(['sheetEmail']);
    }

    public function test_it_does_not_call_google_when_credentials_are_missing(): void
    {
        config(['services.google_sheets.credentials' => null]);
        Http::fake();

        $user = User::factory()->create();
        $leadRequest = LeadRequest::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test('pages::leads.show', ['leadRequest' => $leadRequest])
            ->set('sheetEmail', 'owner@gmail.com')
            ->call('exportToGoogleSheets');

        Http::assertNothingSent();
        $this->assertNull($leadRequest->fresh()->google_sheet_url);
    }
}
