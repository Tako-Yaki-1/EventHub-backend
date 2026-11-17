<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Student',
            'email' => 'new.student@school.edu',
            'student_id' => '2023-90001',
            'year_section' => 'BSIT 1A',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $overrides);
    }

    public function test_a_student_can_register(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload());

        $response->assertCreated()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'student_id', 'year_section', 'role'],
            ])
            ->assertJsonPath('user.role', 'student');

        $this->assertDatabaseHas('users', [
            'email' => 'new.student@school.edu',
            'role' => 'student',
        ]);
    }

    public function test_registration_cannot_assign_the_admin_role(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload([
            'role' => 'admin',
        ]))->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'new.student@school.edu',
            'role' => 'student',
        ]);
    }

    public function test_email_and_student_id_must_be_unique(): void
    {
        User::factory()->create([
            'email' => 'new.student@school.edu',
            'student_id' => '2023-90001',
        ]);

        $this->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'student_id']);
    }

    public function test_password_must_be_confirmed(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload([
            'password_confirmation' => 'different',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_a_student_can_log_in_with_email(): void
    {
        User::factory()->create(['email' => 'jan@school.edu', 'password' => 'password']);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'jan@school.edu',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.email', 'jan@school.edu');
    }

    public function test_a_student_can_log_in_with_student_id(): void
    {
        User::factory()->create([
            'email' => 'jan@school.edu',
            'student_id' => '2023-00123',
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => '2023-00123',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'jan@school.edu');
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'jan@school.edu', 'password' => 'password']);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'jan@school.edu',
            'password' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create(['name' => 'Jan Niño']);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Jan Niño')
            ->assertJsonMissingPath('data.password');
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $token = $user->createToken('web')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Sanctum memoises the resolved user on the guard; reset it to simulate the next HTTP request.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_guests_cannot_list_events(): void
    {
        $this->getJson('/api/v1/events')->assertUnauthorized();
    }
}
