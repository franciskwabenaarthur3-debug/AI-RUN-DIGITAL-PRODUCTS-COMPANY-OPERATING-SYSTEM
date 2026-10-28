<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A founder who must use two-step sign-in and has not set it up can only
 * reach the account security page (and log out) until they do.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->mustUseTwoFactor() && ! $user->hasTwoFactor() && ! $request->routeIs('account.*', 'logout')) {
            return redirect()->route('account.security')->with('status', 'Set up two-step sign-in to continue. Founder accounts need it.');
        }

        return $next($request);
    }
}
