<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Who has access: list accounts and change roles. Role changes land in the
 * audit trail automatically (User is Auditable).
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->withCount('orders')
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($filters['search'] ?? null, function ($q, string $term): void {
                $like = '%'.mb_strtolower($term).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like]));
            })
            ->orderByRaw("CASE role WHEN 'super_admin' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return UserResource::collection($users)->additional([
            'filters' => ['roles' => UserRole::options()],
        ]);
    }

    public function updateRole(UpdateUserRoleRequest $request, User $user)
    {
        if ($user->is($request->user())) {
            return response()->json(['message' => "You can't change your own role."], 422);
        }

        $user->update(['role' => UserRole::from($request->validated('role'))]);

        return new UserResource($user->loadCount('orders'));
    }
}
