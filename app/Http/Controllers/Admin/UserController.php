<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Staff accounts. Applicants register themselves and are listed read-only. */
class UserController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $users = User::with('roles:id,slug,name')
            ->when($request->role, fn ($q, $r) => $q->whereHas('roles', fn ($x) => $x->where('slug', $r)))
            ->when($request->boolean('staff', true), fn ($q) => $q->whereHas('roles', fn ($x) => $x->where('is_staff', true)))
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('email', 'like', "%$t%")))
            ->orderBy('name')
            ->paginate((int) $request->input('per_page', 25));

        return response()->json($users);
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json(User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('slug', $request->input('roles', Role::STAFF)))
            ->with('roles:id,slug')->orderBy('name')->get(['id', 'name', 'email', 'designation']));
    }

    public function roles(): JsonResponse
    {
        return response()->json(Role::orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'], 'email' => mb_strtolower($data['email']), 'mobile' => $data['mobile'] ?? null,
                'designation' => $data['designation'] ?? null, 'is_active' => $data['is_active'] ?? true,
                'password' => Str::password(32), 'email_verified_at' => now(),
            ]);
            $user->roles()->sync(Role::whereIn('slug', $data['roles'])->pluck('id'));
            $this->audit->record('user.created', $user, [], ['email' => $user->email, 'roles' => $data['roles']]);

            return $user;
        });
        // The new officer sets their own password through the reset link.
        Password::sendResetLink(['email' => $user->email]);

        return response()->json($user->load('roles'), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $this->validated($request, $user);
        abort_if($user->id === $request->user()->id && ! in_array(Role::SUPER_ADMIN, $data['roles'], true) && $user->isSuperAdmin(), 422,
            'You cannot remove your own Super Administrator role.');
        abort_if($user->id === $request->user()->id && ! ($data['is_active'] ?? true), 422, 'You cannot deactivate your own account.');

        DB::transaction(function () use ($user, $data) {
            $oldRoles = $user->roles()->pluck('slug')->all();
            $user->fill(collect($data)->only(['name', 'mobile', 'designation', 'is_active'])->all());
            $user->email = mb_strtolower($data['email']);
            $this->audit->recordChanges('user.updated', $user);
            $user->save();
            $user->roles()->sync(Role::whereIn('slug', $data['roles'])->pluck('id'));
            if ($oldRoles != $data['roles']) {
                $this->audit->record('user.roles_changed', $user, ['roles' => $oldRoles], ['roles' => $data['roles']]);
            }
            if (! $user->is_active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });

        return response()->json($user->fresh('roles'));
    }

    public function sendReset(User $user): JsonResponse
    {
        Password::sendResetLink(['email' => $user->email]);
        $this->audit->record('user.reset_link_sent', $user);

        return response()->json(['message' => 'Password reset link sent.']);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'mobile' => ['nullable', 'string', 'max:15'],
            'designation' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(Role::STAFF)],
        ]);
    }
}
