<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FacebookSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FacebookSessionTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fb-sessions-'.uniqid();
        config(['services.facebook.sessions_path' => $this->directory]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_every_user_gets_their_own_session_file(): void
    {
        $session = app(FacebookSession::class);
        [$first, $second] = User::factory()->count(2)->create();

        $this->assertNotSame($session->path($first), $session->path($second));
        $this->assertStringEndsWith($first->id.'.json', $session->path($first));
    }

    public function test_a_user_without_a_saved_session_is_not_connected(): void
    {
        $status = app(FacebookSession::class)->status(User::factory()->create());

        $this->assertSame(['connected' => false, 'name' => null], $status);
    }

    public function test_a_saved_status_is_reused_instead_of_opening_a_browser_again(): void
    {
        $session = app(FacebookSession::class);
        $user = User::factory()->create();

        mkdir($this->directory, 0700, true);
        file_put_contents($session->path($user), '{}');
        Cache::put("facebook-status:{$user->id}", ['connected' => true, 'name' => 'Maria Santos'], 600);

        $this->assertSame(['connected' => true, 'name' => 'Maria Santos'], $session->status($user));
    }

    public function test_disconnecting_removes_the_session_and_status(): void
    {
        $session = app(FacebookSession::class);
        $user = User::factory()->create();

        mkdir($this->directory, 0700, true);
        file_put_contents($session->path($user), '{}');
        Cache::put("facebook-status:{$user->id}", ['connected' => true, 'name' => 'Maria Santos'], 600);

        $session->disconnect($user);

        $this->assertFileDoesNotExist($session->path($user));
        $this->assertSame(['connected' => false, 'name' => null], $session->status($user));
    }

    public function test_connection_progress_is_tracked_per_user(): void
    {
        $session = app(FacebookSession::class);
        [$first, $second] = User::factory()->count(2)->create();

        $session->setConnectionState($first, 'waiting');

        $this->assertSame('waiting', $session->connectionState($first));
        $this->assertNull($session->connectionState($second));
    }
}
