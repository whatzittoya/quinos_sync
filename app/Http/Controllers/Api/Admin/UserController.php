<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return UserResource::collection(User::with('client')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            ...$this->roleRules(),
        ]);

        $user = User::create($this->normalise($data));

        return (new UserResource($user->load('client')))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user): UserResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            ...$this->roleRules(),
        ]);

        $data = $this->normalise($data);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        // Never let the last admin lock everyone out.
        if ($user->isAdmin() && $data['role'] !== User::ROLE_ADMIN
            && User::where('role', User::ROLE_ADMIN)->count() <= 1) {
            abort(422, 'Cannot demote the last admin.');
        }

        $user->update($data);

        return new UserResource($user->load('client'));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($user->is($request->user()), 422, 'You cannot delete your own account.');

        $user->delete();

        return response()->json(['ok' => true]);
    }

    private function roleRules(): array
    {
        return [
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_CLIENT])],
            'client_id' => ['required_if:role,client', 'nullable', 'exists:clients,id'],
        ];
    }

    private function normalise(array $data): array
    {
        if ($data['role'] === User::ROLE_ADMIN) {
            $data['client_id'] = null;
        }

        return $data;
    }
}
