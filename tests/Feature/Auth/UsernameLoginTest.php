<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_log_in_with_their_username(): void
    {
        $user = User::factory()->create(['username' => 'sadmin', 'password' => 'secret-pass-1']);

        $this->post(route('login.store'), ['email' => 'sadmin', 'password' => 'secret-pass-1'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_username_login_rejects_a_wrong_password(): void
    {
        User::factory()->create(['username' => 'sadmin', 'password' => 'secret-pass-1']);

        $this->post(route('login.store'), ['email' => 'sadmin', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
