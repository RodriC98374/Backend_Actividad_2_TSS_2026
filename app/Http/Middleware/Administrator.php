<?php

namespace App\Http\Middleware;

use App\Support\ApiProblem;
use Closure;
use Illuminate\Http\Request;

class Administrator
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->is_admin || ! $request->user()->tokenCan('admin')) {
            throw new ApiProblem('forbidden', 'Acceso administrativo requerido.', 403);
        }

        return $next($request);
    }
}
