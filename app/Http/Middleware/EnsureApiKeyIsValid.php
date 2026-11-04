<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiKeyIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('api.key');

        // If no API key is configured, skip validation and allow the request.
        if (! is_string($expected) || $expected === '') {
            return $next($request);
        }

        $provided = (string) $request->header('X-Api-Key', '');

        if (! hash_equals($expected, $provided)) {
            abort(401, 'Invalid API key.');
        }

        return $next($request);
    }
}
