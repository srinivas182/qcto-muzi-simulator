<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Access;

use App\Authorization\RoleService;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserRoleController extends Controller
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly TenantContext $context,
    ) {}

    public function show(string $user): JsonResponse
    {
        $person = $this->find($user);

        return response()->json(['data' => [
            'user_id' => $person->id,
            'roles' => $person->roles()->get()->map(fn (Role $r) => [
                'id' => $r->id, 'key' => $r->key, 'name' => $r->name, 'centre_id' => $r->getRelationValue('pivot')?->centre_id,
            ]),
            'permissions' => $person->permissions(),
        ]]);
    }

    public function update(Request $request, string $user): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*.role_id' => ['required', 'uuid'],
            'roles.*.centre_id' => ['nullable', 'uuid'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $person = $this->find($user);
        /** @var list<array{role_id: string, centre_id?: string|null}> $assignments */
        $assignments = $data['roles'];
        $this->roles->assign($actor, $person, $assignments);

        return $this->show($person->id);
    }

    private function find(string $id): User
    {
        // Row-level security limits this to the current provider; at HQ, only HQ staff.
        return User::query()
            ->when($this->context->isPlatform(), fn ($q) => $q->whereNull('tenant_id'))
            ->findOrFail($id);
    }
}
