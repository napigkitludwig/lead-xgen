<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\FacebookSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Opens the Facebook login window and saves the session for one user.
 */
class ConnectFacebookAccount implements ShouldQueue
{
    use Queueable;

    public int $timeout = 400;

    public int $tries = 1;

    public function __construct(public User $user) {}

    public function handle(FacebookSession $session): void
    {
        $session->connect($this->user);
        $session->setConnectionState($this->user, 'done');
    }

    public function failed(Throwable $exception): void
    {
        app(FacebookSession::class)->setConnectionState($this->user, 'failed: '.$exception->getMessage());
    }
}
