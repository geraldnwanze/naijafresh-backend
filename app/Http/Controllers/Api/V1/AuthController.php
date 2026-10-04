<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityEvent;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Support\Analytics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, Analytics $analytics, ActivityLogger $activity)
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'role' => UserRole::Customer,
        ]);

        $analytics->track('user_registered', ['user_id' => $user->id]);
        $activity->log(ActivityEvent::UserRegistered, 'New account created', $user, user: $user);

        return response()->json([
            'token' => $user->createToken($this->deviceName($request))->plainTextToken,
            'user' => new UserResource($user),
        ], 201);
    }

    public function login(LoginRequest $request, ActivityLogger $activity)
    {
        $data = $request->validated();

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $activity->log(ActivityEvent::LoginFailed, "Failed sign-in for {$data['email']}", properties: ['email' => $data['email']], user: $user);

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $activity->log(ActivityEvent::LoginSucceeded, 'Signed in', user: $user, properties: ['role' => $user->role->value]);

        return response()->json([
            'token' => $user->createToken($this->deviceName($request))->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request, ActivityLogger $activity)
    {
        $activity->log(ActivityEvent::LoggedOut, 'Signed out', user: $request->user());
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function user(Request $request)
    {
        return new UserResource($request->user());
    }

    private function deviceName(Request $request): string
    {
        return $request->string('device_name')->toString()
            ?: ($request->userAgent() ? mb_substr($request->userAgent(), 0, 100) : 'api');
    }
}
