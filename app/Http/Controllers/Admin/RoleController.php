<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The role × permission matrix. Super Administrator holds everything implicitly. */
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'roles' => Role::with('permissions:id,slug')->where('slug', '!=', Role::SUPER_ADMIN)->where('is_staff', true)->get()
                ->map(fn ($r) => ['id' => $r->id, 'slug' => $r->slug, 'name' => $r->name, 'permissions' => $r->permissions->pluck('slug')]),
            'permissions' => Permission::orderBy('group')->orderBy('id')->get(['slug', 'name', 'group', 'is_sensitive']),
        ]);
    }

    public function update(Request $request, Role $role, AuditLogger $audit): JsonResponse
    {
        abort_if($role->slug === Role::SUPER_ADMIN || ! $role->is_staff, 422, 'This role’s permissions cannot be changed.');
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['exists:permissions,slug']]);
        $old = $role->permissions()->pluck('slug')->all();
        $role->permissions()->sync(Permission::whereIn('slug', $data['permissions'])->pluck('id'));
        $audit->record('role.permissions_changed', $role, ['permissions' => $old], ['permissions' => $data['permissions']]);

        return response()->json(['ok' => true]);
    }
}
