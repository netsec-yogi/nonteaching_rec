<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** Creates the first Super Administrator on a fresh installation. They set their password via the emailed reset link. */
class CreateSuperAdmin extends Command
{
    protected $signature = 'recruitment:create-admin {email} {name} {--designation=}';

    protected $description = 'Create a Super Administrator account and email them a link to set their password';

    public function handle(AuditLogger $audit): int
    {
        $email = mb_strtolower($this->argument('email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        $user = User::firstOrCreate(['email' => $email], [
            'name' => $this->argument('name'),
            'designation' => $this->option('designation'),
            'password' => Str::password(40),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->roles()->syncWithoutDetaching(Role::where('slug', Role::SUPER_ADMIN)->pluck('id'));
        $audit->record('user.super_admin_created', $user, [], ['email' => $email], actorType: 'system', actorLabel: 'console');

        Password::sendResetLink(['email' => $email]);
        $this->info("Super Administrator {$email} is ready. A password-setting link has been emailed (check the queue worker is running).");

        return self::SUCCESS;
    }
}
