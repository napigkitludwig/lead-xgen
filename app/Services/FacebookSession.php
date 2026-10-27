<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Per-user Facebook login sessions.
 *
 * Each user connects their own Facebook account: a browser window opens on the
 * machine running the app, the user logs in, and the session is stored for that
 * user only. A web page cannot read another site's cookies, so the app keeps and
 * re-checks its own saved session instead of reading the user's everyday browser.
 */
class FacebookSession
{
    private const STATUS_TTL_SECONDS = 600;

    private const LOGIN_TIMEOUT_SECONDS = 330;

    private const CHECK_TIMEOUT_SECONDS = 90;

    public function path(User $user): string
    {
        return rtrim(config('services.facebook.sessions_path'), '/\\').DIRECTORY_SEPARATOR.$user->id.'.json';
    }

    public function exists(User $user): bool
    {
        return is_file($this->path($user));
    }

    /**
     * Whether the user's saved session is still logged in, and as whom.
     * Results are cached for a few minutes because the check starts a browser.
     *
     * @return array{connected: bool, name: ?string}
     */
    public function status(User $user, bool $fresh = false): array
    {
        if (! $this->exists($user)) {
            return ['connected' => false, 'name' => null];
        }

        if ($fresh) {
            Cache::forget($this->statusKey($user));
        }

        return Cache::remember($this->statusKey($user), self::STATUS_TTL_SECONDS, function () use ($user): array {
            $result = $this->run('facebook-check.mjs', $user, self::CHECK_TIMEOUT_SECONDS);

            return ['connected' => (bool) ($result['loggedIn'] ?? false), 'name' => $result['name'] ?? null];
        });
    }

    /**
     * Open the login window and save the session. Blocks until the user has logged in.
     *
     * @return array{connected: bool, name: ?string}
     */
    public function connect(User $user): array
    {
        $result = $this->run('facebook-login.mjs', $user, self::LOGIN_TIMEOUT_SECONDS);

        $status = ['connected' => true, 'name' => $result['name'] ?? null];

        Cache::put($this->statusKey($user), $status, self::STATUS_TTL_SECONDS);

        return $status;
    }

    public function disconnect(User $user): void
    {
        if ($this->exists($user)) {
            unlink($this->path($user));
        }

        Cache::forget($this->statusKey($user));
        $this->clearConnectionState($user);
    }

    /**
     * Progress of a "log in to Facebook" request: waiting, done, or "failed: reason".
     */
    public function connectionState(User $user): ?string
    {
        return Cache::get($this->stateKey($user));
    }

    public function setConnectionState(User $user, string $state): void
    {
        Cache::put($this->stateKey($user), $state, 900);
    }

    public function clearConnectionState(User $user): void
    {
        Cache::forget($this->stateKey($user));
    }

    /**
     * @return array<string, mixed>
     */
    private function run(string $script, User $user, int $timeout): array
    {
        $directory = dirname($this->path($user));

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $process = new Process(
            [config('services.scraper.node'), resource_path("scrapers/{$script}"), $this->path($user)],
            base_path(),
            null,
            null,
            $timeout,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'The Facebook browser step failed.');
        }

        $result = json_decode($process->getOutput(), true);

        if (! is_array($result)) {
            throw new RuntimeException('The Facebook browser step returned invalid output.');
        }

        return $result;
    }

    private function statusKey(User $user): string
    {
        return "facebook-status:{$user->id}";
    }

    private function stateKey(User $user): string
    {
        return "facebook-connect:{$user->id}";
    }
}
