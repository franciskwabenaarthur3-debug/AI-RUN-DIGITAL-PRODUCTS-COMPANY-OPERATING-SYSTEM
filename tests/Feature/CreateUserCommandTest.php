<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_founder_with_hashed_password(): void
    {
        $this->artisan('company-os:create-user', ['email' => 'founder@example.com', '--name' => 'Founder'])
            ->expectsQuestion('Password (min 12 characters)', 'a-long-password-1')
            ->expectsQuestion('Confirm password', 'a-long-password-1')
            ->assertSuccessful();

        $user = User::query()->where('email', 'founder@example.com')->sole();
        $this->assertSame(UserRole::Founder, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNotSame('a-long-password-1', $user->password);
        $this->assertTrue(Hash::check('a-long-password-1', $user->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'actor_type' => 'console', 'resource_id' => $user->id]);
    }

    public function test_rejects_short_password_and_unknown_role(): void
    {
        $this->artisan('company-os:create-user', ['email' => 'x@example.com', '--name' => 'X', '--role' => 'god'])
            ->expectsQuestion('Password (min 12 characters)', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('company-os:create-user', ['email' => 'taken@example.com', '--name' => 'Y'])
            ->expectsQuestion('Password (min 12 characters)', 'a-long-password-1')
            ->expectsQuestion('Confirm password', 'a-long-password-1')
            ->assertFailed();
    }

    public function test_role_and_activation_are_not_mass_assignable(): void
    {
        $user = new User(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'role' => 'founder', 'is_active' => false]);

        $this->assertNull($user->role);
        $this->assertNull($user->is_active);
    }
}
