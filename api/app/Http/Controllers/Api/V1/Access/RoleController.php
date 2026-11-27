<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Access;

use App\Authorization\PermissionRegistry;
use App\Authorization\RoleService;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Roles for the current area (a provider, or HQ when called under /platform). */
final class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function permissions(): JsonResponse
    {
        return response()->json(['data' => PermissionRegistry::catalogue($this->roles->scope())]);
    }

    public function index(): JsonResponse
    {
        $scopes = $this->roles->scope() === Role::SCOPE_PLATFORM ? [Role::SCOPE_PLATFORM] : [Role::SCOPE_TENANT, Role::SCOPE_EXTERNAL];
        $counts = DB::table('role_user')->select('role_id', DB::raw('count(*) as total'))->groupBy('role_id')->pluck('total', 'role_id');

        $roles = Role::query()->whereIn('scope', $scopes)->orderByDesc('is_system')->orderBy('name')->get()
            ->map(fn (Role $role) => $this->present($role, (int) ($counts[$role->id] ?? 0)));

        return response()->json(['data' => $roles]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $role = $this->roles->create($this->actor($request), $data['name'], $data['description'] ?? null, $data['permissions']);

        return response()->json(['data' => $this->present($role, 0)], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $this->validated($request);
        $role = $this->roles->update($this->actor($request), $role, $data['name'], $data['description'] ?? null, $data['permissions']);

        return response()->json(['data' => $this->present($role, 0)]);
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->roles->delete($role);

        return response()->json(['message' => 'Role deleted.']);
    }

    /** "Preview as this role": what a person with it can do and which menu they see. */
    public function preview(Role $role): JsonResponse
    {
        return response()->json(['data' => $this->roles->preview($role)]);
    }

    /** @return array{name: string, description?: string|null, permissions: list<string>} */
    private function validated(Request $request): array
    {
        /** @var array{name: string, description?: string|null, permissions: list<string>} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'max:60'],
        ]);

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Role $role, int $people): array
    {
        $permissions = $role->effectivePermissions();

        return [
            'id' => $role->id,
            'key' => $role->key,
            'name' => $role->name,
            'description' => $role->description,
            'scope' => $role->scope,
            'is_system' => $role->is_system,
            'permissions' => $permissions,
            'permission_labels' => PermissionRegistry::labels($permissions),
            'people' => $people,
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
