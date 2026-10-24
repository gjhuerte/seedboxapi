<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional HTTP Basic auth for the dashboard (UI_USERNAME + UI_PASSWORD).
 */
class BasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $username = (string) config('seedbox.ui_username');
        $password = (string) config('seedbox.ui_password');

        if ($username === '' || $password === '') {
            return $next($request);
        }

        $valid = hash_equals($username, (string) $request->getUser())
            && hash_equals($password, (string) $request->getPassword());

        if (! $valid) {
            return response('Authentication required.', 401, [
                'WWW-Authenticate' => 'Basic realm="seedboxapi", charset="UTF-8"',
            ]);
        }

        return $next($request);
    }
}
