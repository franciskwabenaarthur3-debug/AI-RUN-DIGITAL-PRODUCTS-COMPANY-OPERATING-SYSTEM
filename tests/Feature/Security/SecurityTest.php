<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_carries_security_headers(): void
    {
        foreach ([$this->get('/login'), $this->actingAs(User::factory()->operator()->create())->get('/dashboard')] as $response) {
            $csp = $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("script-src 'self'", $csp);
            $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
            $this->assertStringContainsString("frame-ancestors 'none'", $csp);
            $this->assertStringContainsString("object-src 'none'", $csp);
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('X-Frame-Options', 'DENY');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
    }

    public function test_pages_have_no_inline_scripts(): void
    {
        $files = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)|\son(click|change|submit|load|input)=/i', file_get_contents($file->getPathname()), $file->getPathname());
            $files++;
        }
        $this->assertGreaterThan(40, $files);
    }

    public function test_every_signed_in_page_and_action_refuses_guests(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            if (! in_array('auth', $route->gatherMiddleware(), true)) {
                continue;
            }
            $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->call($method, '/'.ltrim($uri, '/'));
                $this->assertTrue($response->isRedirect(route('login')), "{$method} /{$uri} answered {$response->status()} to a guest");
                $checked++;
            }
        }
        $this->assertGreaterThan(80, $checked);
    }

    public function test_only_login_and_health_are_public(): void
    {
        $public = collect(Route::getRoutes())->reject(fn ($r) => in_array('auth', $r->gatherMiddleware(), true))
            ->map(fn ($r) => $r->uri())->reject(fn ($uri) => str_starts_with($uri, '_') || str_starts_with($uri, 'storage'))->values()->unique()->sort()->values()->all();

        $this->assertSame(['/', 'login', 'login/two-factor', 'up'], $public);
    }

    public function test_session_and_cookie_settings(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertTrue(config('session.encrypt'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_logging_out_ends_the_session(): void
    {
        $user = User::factory()->operator()->create();
        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
