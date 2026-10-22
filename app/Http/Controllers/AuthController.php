<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_FAILURES = 5;

    private const LOCK_MINUTES = 15;

    public function __construct(private AuditLogger $audit) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => mb_strtoupper(trim($request->name)),
                'email' => $request->email,
                'mobile' => $request->mobile,
                'password' => $request->password,
                'is_active' => true,
            ]);
            $user->roles()->attach(Role::where('slug', Role::APPLICANT)->value('id'));
            $this->audit->record('account.registered', $user, [], ['email' => $user->email], user: $user);

            return $user;
        });

        $user->sendEmailVerificationNotification();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $this->userPayload($user)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $email = mb_strtolower($credentials['email']);
        $user = User::where('email', $email)->first();

        if ($user?->locked_until?->isFuture()) {
            throw ValidationException::withMessages(['email' => 'Too many failed attempts. Try again after '.$user->locked_until->diffForHumans(null, true).'.']);
        }

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            if ($user) {
                $failures = $user->failed_logins + 1;
                $user->forceFill([
                    'failed_logins' => $failures >= self::MAX_FAILURES ? 0 : $failures,
                    'locked_until' => $failures >= self::MAX_FAILURES ? now()->addMinutes(self::LOCK_MINUTES) : null,
                ])->save();
                $this->audit->record('account.login_failed', $user, [], [], ['locked' => $failures >= self::MAX_FAILURES], user: $user);
            }
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'This account has been deactivated. Contact the Recruitment Section.']);
        }

        Auth::guard('web')->login($user, (bool) $request->boolean('remember'));
        $request->session()->regenerate();
        $user->forceFill(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $this->audit->record('account.login', $user, user: $user);

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($user) {
            $this->audit->record('account.logout', $user, user: $user);
        }

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user() ? $this->userPayload($request->user()) : null]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'A new verification link has been sent to your email address.']);
    }

    /** Signed link from the verification email; redirects into the SPA. */
    public function verifyEmail(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            $this->audit->record('account.email_verified', $user, user: $user);
        }

        return redirect(rtrim(config('recruitment.frontend_url'), '/').'/dashboard?verified=1');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => mb_strtolower($request->email)]);

        // Same answer whether or not the account exists.
        return response()->json(['message' => 'If an account exists for this email, a password reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->mixedCase()->numbers()->symbols()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'failed_logins' => 0, 'locked_until' => null])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                event(new PasswordReset($user));
                $this->audit->record('account.password_reset', $user, user: $user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => 'Your password has been reset. You can now sign in.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->mixedCase()->numbers()->symbols()],
        ]);
        $user = $request->user();
        $user->forceFill(['password' => $request->password])->save();
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        $this->audit->record('account.password_changed', $user, user: $user);

        return response()->json(['message' => 'Password changed.']);
    }

    public static function userPayload(User $user): array
    {
        $user->loadMissing('roles');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'designation' => $user->designation,
            'email_verified' => $user->hasVerifiedEmail(),
            'roles' => $user->roles->pluck('slug')->values(),
            'permissions' => $user->isStaff() ? $user->permissionSlugs()->values() : [],
            'is_staff' => $user->isStaff(),
            'is_super_admin' => $user->isSuperAdmin(),
        ];
    }
}
