<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Api;
use App\Support\ApiProblem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $v = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', strtolower($v['email']))->first();
        if (! $user || ! Hash::check($v['password'], $user->password) || ! $user->is_admin) {
            throw new ApiProblem('invalid_credentials', 'Credenciales no válidas.', 401);
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
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
