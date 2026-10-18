<?php

namespace App\Http\Middleware;

use App\Models\FormInvitation;
use App\Support\ApiProblem;
use Closure;
use Illuminate\Http\Request;

class CaptureAccess
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        $invitation = $token ? FormInvitation::where('token_hash', hash('sha256', $token))->first() : null;
        if (! $invitation || $invitation->revoked_at || $invitation->expires_at->isPast()) {
            throw new ApiProblem('invalid_capture_access', 'Acceso de captura inválido o vencido.', 401);
        }
        $request->attributes->set('invitation', $invitation);

        return $next($request);
    }
}
