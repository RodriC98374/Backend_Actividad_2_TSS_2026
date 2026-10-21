<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Api;
use App\Support\ApiProblem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $v = $request->validate(['email' => 'required|email', 'password' => 'required|string', 'browser_session' => 'sometimes|boolean']);
        $user = User::where('email', strtolower($v['email']))->first();
        if (! $user || ! Hash::check($v['password'], $user->password) || ! $user->is_admin) {
            throw new ApiProblem('invalid_credentials', 'Credenciales no válidas.', 401);
        }
        if ($request->boolean('browser_session')) {
            abort_unless($request->hasSession(), 422, 'Origen de sesión no configurado.');
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return Api::data(['user' => $user]);
        }
        $expires = now()->addHours(config('surveys.token_hours'));

        return Api::data(['access_token' => $user->createToken('frontend', ['admin'], $expires)->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expires->toIso8601String(), 'user' => $user]);
    }

    public function me(Request $request)
    {
        return Api::data($request->user());
    }

    public function logout(Request $request)
    {
        if ($request->user()->currentAccessToken() instanceof \Laravel\Sanctum\TransientToken) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } else {
            $request->user()->currentAccessToken()->delete();
        }

        return response()->noContent();
    }
}
