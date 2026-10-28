<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on every response. Scripts may only come from
 * this site (no inline scripts); inline style attributes are allowed
 * because the proposal document shares its markup with the email.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $dev = $this->viteDevServer();
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self'".($dev ? " {$dev}" : ''),
            "style-src 'self' 'unsafe-inline'".($dev ? " {$dev}" : ''),
            "img-src 'self' data:",
            "font-src 'self'".($dev ? " {$dev}" : ''),
            "connect-src 'self'".($dev ? ' '.$dev.' '.preg_replace('#^http#', 'ws', $dev) : ''),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /** The Vite dev server, only while `npm run dev` is running locally. */
    private function viteDevServer(): ?string
    {
        if (app()->isProduction() || ! is_file(public_path('hot'))) {
            return null;
        }

        $url = rtrim(trim((string) file_get_contents(public_path('hot'))), '/');

        return preg_match('#^https?://[\w.\-\[\]:]+$#', $url) ? $url : null;
    }
}
