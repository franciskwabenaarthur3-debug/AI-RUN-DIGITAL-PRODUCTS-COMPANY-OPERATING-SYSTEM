<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Security\Totp;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TwoFactorService
    {
        return app(TwoFactorService::class);
    }

    /** @return array{User, list<string>} */
    private function enrolled(array $state = []): array
    {
        $user = User::factory()->founder()->create(['email' => 'founder@example.test', 'password' => 'correct-horse-battery'] + $state);
        $this->service()->begin($user);
        $user->refresh();
        $codes = $this->service()->confirm($user, Totp::code($user->two_factor_secret, Totp::step() - 1));

        return [$user->fresh(), $codes];
    }

    private function now(User $user): string
    {
        return Totp::code($user->two_factor_secret, Totp::step());
    }

    public function test_sign_in_needs_the_code_after_the_password(): void
    {
        [$user] = $this->enrolled();

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])->assertRedirect('/login/two-factor');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');

        $this->post('/login/two-factor', ['otp' => '000000'])->assertSessionHasErrors('otp');
        $this->assertGuest();

        $this->post('/login/two-factor', ['otp' => $this->now($user)])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_failed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login']);
    }

    public function test_a_code_works_once(): void
    {
        [$user] = $this->enrolled();
        $code = $this->now($user);
        $this->assertSame('app', $this->service()->verify($user, $code, null));
        $this->assertNull($this->service()->verify($user->fresh(), $code, null));
    }

    public function test_recovery_codes_work_once_each(): void
    {
        [$user, $codes] = $this->enrolled();
        $this->assertCount(8, $codes);
        $this->assertNotContains($codes[0], $user->two_factor_recovery_codes, 'Only hashes are stored.');

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery']);
        $this->post('/login/two-factor', ['recovery_code' => strtoupper($codes[0])])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(7, $this->service()->remainingRecoveryCodes($user->fresh()));
        $this->assertNull($this->service()->verify($user->fresh(), null, $codes[0]));
    }

    public function test_too_many_wrong_codes_drop_the_pending_sign_in(): void
    {
        [$user] = $this->enrolled();
        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery']);

        foreach (range(1, 4) as $i) {
            $this->post('/login/two-factor', ['otp' => '000000'])->assertSessionHasErrors('otp');
        }
        $this->post('/login/two-factor', ['otp' => '000000'])->assertRedirect('/login');
        $this->post('/login/two-factor', ['otp' => $this->now($user)])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_pending_sign_in_expires(): void
    {
        [$user] = $this->enrolled();
        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery']);
        $this->travel(6)->minutes();

        $this->post('/login/two-factor', ['otp' => $this->now($user)])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_challenge_needs_a_correct_password_first(): void
    {
        [$user] = $this->enrolled();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password-here'])->assertSessionHasErrors('email');

        $this->get('/login/two-factor')->assertRedirect('/login');
        $this->post('/login/two-factor', ['otp' => $this->now($user)])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_deactivated_people_cannot_finish_signing_in(): void
    {
        [$user] = $this->enrolled();
        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery']);
        $user->forceFill(['is_active' => false])->save();

        $this->post('/login/two-factor', ['otp' => $this->now($user)])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_founders_must_set_it_up_before_using_the_app(): void
    {
        config(['company_os.security.require_founder_two_factor' => true]);
        $founder = User::factory()->founder()->create();
        $operator = User::factory()->operator()->create();

        $this->actingAs($founder)->get('/dashboard')->assertRedirect('/account/security');
        $this->actingAs($founder)->get('/leads')->assertRedirect('/account/security');
        $this->actingAs($founder)->get('/account/security')->assertOk()->assertSee('Set up two-step sign-in');
        $this->actingAs($operator)->get('/dashboard')->assertOk();

        // Set up through the page.
        $this->actingAs($founder)->post('/account/two-factor')->assertRedirect('/account/security');
        $founder->refresh();
        $this->actingAs($founder)->get('/account/security')->assertOk()->assertSee('<svg', false);
        $this->actingAs($founder)->post('/account/two-factor/confirm', ['otp' => '000000'])->assertSessionHasErrors('two_factor');
        $this->actingAs($founder)->post('/account/two-factor/confirm', ['otp' => $this->now($founder)])->assertSessionHas('recovery_codes');
        $this->actingAs($founder->fresh())->get('/dashboard')->assertOk();

        // And cannot turn it off.
        $this->actingAs($founder->fresh())->post('/account/two-factor/disable', ['current_password' => 'password', 'otp' => '123456'])->assertSessionHasErrors('two_factor');
        $this->assertTrue($founder->fresh()->hasTwoFactor());
    }

    public function test_others_can_turn_it_off_with_password_and_code(): void
    {
        $operator = User::factory()->operator()->create(['password' => 'correct-horse-battery']);
        $this->service()->begin($operator);
        $operator->refresh();
        $this->service()->confirm($operator, Totp::code($operator->two_factor_secret, Totp::step() - 1));
        $operator->refresh();

        $this->actingAs($operator)->post('/account/two-factor/disable', ['current_password' => 'wrong-password', 'otp' => $this->now($operator)])->assertSessionHasErrors('two_factor');
        $this->actingAs($operator)->post('/account/two-factor/disable', ['current_password' => 'correct-horse-battery', 'otp' => $this->now($operator)])->assertSessionHasNoErrors();
        $this->assertFalse($operator->fresh()->hasTwoFactor());
        $this->assertNull($operator->fresh()->two_factor_secret);
    }

    public function test_the_secret_is_encrypted_and_never_logged(): void
    {
        [$user, $codes] = $this->enrolled();
        $raw = \DB::table('users')->where('id', $user->id)->first();

        $this->assertStringNotContainsString($user->two_factor_secret, $raw->two_factor_secret);
        $this->assertStringNotContainsString($codes[0], (string) $raw->two_factor_recovery_codes);
        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        foreach (AuditLog::query()->get() as $log) {
            $this->assertStringNotContainsString($user->two_factor_secret, json_encode($log->metadata));
        }
    }

    public function test_the_console_reset_turns_it_off(): void
    {
        [$user] = $this->enrolled();
        $this->artisan('company-os:reset-two-factor', ['email' => $user->email])->expectsConfirmation("Turn off two-step sign-in for {$user->email}? Check it is really them first.", 'yes')->assertSuccessful();

        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_reset', 'actor_type' => 'console']);
    }

    public function test_people_change_their_own_password(): void
    {
        $user = User::factory()->operator()->create(['password' => 'correct-horse-battery']);

        $this->actingAs($user)->put('/account/password', ['current_password' => 'nope', 'password' => 'a-new-long-password', 'password_confirmation' => 'a-new-long-password'])->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put('/account/password', ['current_password' => 'correct-horse-battery', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->actingAs($user)->put('/account/password', ['current_password' => 'correct-horse-battery', 'password' => 'a-new-long-password', 'password_confirmation' => 'a-new-long-password'])->assertSessionHasNoErrors();

        $this->assertTrue(\Hash::check('a-new-long-password', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed']);
    }
}
