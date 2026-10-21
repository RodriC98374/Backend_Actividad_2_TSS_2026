<?php
namespace App\Http\Middleware;

class BrowserSession extends \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful
{
    public function handle($request, $next)
    {
        if ($request->bearerToken()) {
            return $next($request);
        }
        return parent::handle($request, $next);
    }
}
