<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/** Registration verification and password reset, through the editable templates. */
class AccountMailer
{
    public function __construct(private TemplateMailer $mailer) {}

    public function sendVerification(User $user): void
    {
        $url = URL::temporarySignedRoute('verification.verify', now()->addHours(48), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->mailer->send('email_verification', $user->email, $user->name, [
            'name' => $user->name,
            'verification_url' => $url,
        ], $user);
    }

    public function sendPasswordReset(User $user, string $token): void
    {
        $url = rtrim(config('recruitment.frontend_url'), '/').'/reset-password?token='.$token.'&email='.urlencode($user->email);

        $this->mailer->send('password_reset', $user->email, $user->name, [
            'name' => $user->name,
            'reset_url' => $url,
            'minutes' => config('auth.passwords.users.expire', 60),
        ], $user);
    }
}
