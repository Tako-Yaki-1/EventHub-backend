<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_can_update_their_profile(): void
    {
        $student = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@school.edu',
            'student_id' => '2023-00001',
            'year_section' => 'BSIT 1A',
        ]);
        Sanctum::actingAs($student);

        $this->putJson('/api/v1/profile', [
            'name' => 'New Name',
            'email' => 'new@school.edu',
            'student_id' => '2023-00002',
            'year_section' => 'BSIT 2B',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Profile updated.')
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'new@school.edu')
            ->assertJsonPath('data.student_id', '2023-00002')
            ->assertJsonPath('data.year_section', 'BSIT 2B');

        $this->assertDatabaseHas('users', ['id' => $student->id, 'email' => 'new@school.edu']);
    }

    public function test_email_and_student_id_stay_unique_but_allow_the_current_user(): void
    {
        $other = User::factory()->create(['email' => 'taken@school.edu', 'student_id' => '2023-11111']);
        $student = User::factory()->create(['email' => 'mine@school.edu', 'student_id' => '2023-22222']);
        Sanctum::actingAs($student);

        $this->putJson('/api/v1/profile', ['email' => $other->email])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->putJson('/api/v1/profile', ['student_id' => $other->student_id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_id');

        $this->putJson('/api/v1/profile', [
            'email' => 'mine@school.edu',
            'student_id' => '2023-22222',
        ])->assertOk();
    }

    public function test_a_student_cannot_clear_their_student_id(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/profile', ['student_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_id');
    }

    public function test_a_password_change_requires_the_current_password(): void
    {
        $student = User::factory()->create(['password' => 'old-password']);
        Sanctum::actingAs($student);

        $this->putJson('/api/v1/profile', [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->putJson('/api/v1/profile', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->putJson('/api/v1/profile', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'login' => $student->email,
            'password' => 'new-password',
        ])->assertOk();
    }

    public function test_changing_the_password_revokes_all_sessions(): void
    {
        $student = User::factory()->create(['password' => 'old-password']);
        $student->createToken('web (Chrome on Windows)');
        $student->createToken('web (Safari on macOS)');

        Sanctum::actingAs($student);

        $this->putJson('/api/v1/profile', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_the_password_confirmation_must_match(): void
    {
        $student = User::factory()->create(['password' => 'old-password']);
        Sanctum::actingAs($student);

        $this->putJson('/api/v1/profile', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'different',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_guests_cannot_update_a_profile(): void
    {
        $this->putJson('/api/v1/profile', ['name' => 'Nobody'])->assertUnauthorized();
    }
}
