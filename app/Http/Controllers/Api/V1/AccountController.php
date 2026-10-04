<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\UserResource;

class AccountController extends Controller
{
    public function update(UpdateProfileRequest $request)
    {
        $data = $request->validated();
        $user = $request->user();

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();

        return new UserResource($user);
    }
}
