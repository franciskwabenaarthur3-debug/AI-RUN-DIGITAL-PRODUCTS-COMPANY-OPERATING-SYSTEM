<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public static function protectedRoutes(): array
    {
        return [
            'dashboard' => ['get', '/dashboard'],
            'settings' => ['get', '/settings'],
            'settings update' => ['put', '/settings'],
            'audit log' => ['get', '/audit-log'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_guest_cannot_access_protected_routes(string $method, string $uri): void
    {
        $this->{$method}($uri)->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_screen_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Log in');
    }

    public function test_there_is_no_public_registration(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => 'x@example.com'])->assertNotFound();
    }

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_id' => $user->id]);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();

        $failure = AuditLog::query()->where('action', 'auth.login_failed')->sole();
        $this->assertSame('system', $failure->actor_type);
        $this->assertStringNotContainsString($user->email, json_encode($failure->metadata));
        $this->assertStringNotContainsString('wrong-password', json_encode($failure->metadata));
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();
        RateLimiter::clear(strtolower($user->email).'|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        // Even the correct password is refused while locked out.
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));

        $this->assertGuest();
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->session(['marker' => 'present']);

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertNull(session('marker'));
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'actor_id' => $user->id]);
    }

    public function test_logout_requires_post_with_csrf_token(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/logout')->assertStatus(405);
    }

    public function test_user_deactivated_mid_session_is_logged_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $user->forceFill(['is_active' => false])->save();

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_authenticated_user_is_redirected_away_from_login(): void
    {
        $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/dashboard');
    }
}
