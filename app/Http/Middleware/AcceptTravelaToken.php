<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production nginx often drops the Authorization header before PHP.
 * The admin app also sends the Sanctum token as X-Travela-Token.
 */
class AcceptTravelaToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->bearerToken()) {
            $token = $request->headers->get('X-Travela-Token');
            if (is_string($token) && $token !== '') {
                $request->headers->set('Authorization', 'Bearer '.$token);
            }
        }

        return $next($request);
    }
}
