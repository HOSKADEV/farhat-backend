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

        if (! is_string($expected) || $expected === '') {
            abort(500, 'API key is not configured.');
        }

        $provided = (string) $request->header('X-Api-Key', '');

        if (! hash_equals($expected, $provided)) {
            abort(401, 'Invalid API key.');
        }

        return $next($request);
    }
}
