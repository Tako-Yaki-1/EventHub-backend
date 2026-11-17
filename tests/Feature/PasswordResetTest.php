<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_existing_email_receives_a_reset_link_pointing_at_the_frontend(): void
    {
        NotificationFacade::fake();

        $user = User::factory()->create(['email' => 'jan.nino@school.edu']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', 'If that email is registered, a reset link has been sent.');

        NotificationFacade::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            return str_contains($notification->toMail($user)->actionUrl, config('app.frontend_url').'/reset-password?');
        });
    }

    public function test_an_unknown_email_gets_the_same_answer_without_sending_anything(): void
    {
        NotificationFacade::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@school.edu'])
            ->assertOk()
            ->assertJsonPath('message', 'If that email is registered, a reset link has been sent.');

        NotificationFacade::assertNothingSent();
    }

    public function test_a_valid_token_resets_the_password_and_revokes_old_tokens(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $oldToken = $user->createToken('web')->plainTextToken;
        $resetToken = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $resetToken,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Password reset.');

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->withHeader('Authorization', "Bearer {$oldToken}")
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'new-password',
        ])->assertOk();
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_the_reset_payload_is_validated(): void
    {
        $this->postJson('/api/v1/auth/reset-password', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['token', 'email', 'password']);
    }
}
