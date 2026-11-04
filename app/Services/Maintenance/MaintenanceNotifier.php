<?php

namespace App\Services\Maintenance;

use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * In-app alerts for maintenance events, to active Super Administrators and holders of the given
 * permission (plus whoever started the operation). Messages never contain credentials, keys or paths.
 */
class MaintenanceNotifier
{
    public function notify(string $permission, string $title, string $message, string $link, ?int $alsoUserId = null): void
    {
        try {
            $users = $this->recipients($permission, $alsoUserId);
            if ($users->isNotEmpty()) {
                Notification::send($users, new StaffAlert($title, $message, $link));
            }
        } catch (Throwable $e) {
            // A notice must not turn a finished backup or restore into a failure.
            Log::warning('System maintenance notification failed', ['title' => $title, 'error' => $e->getMessage()]);
        }
    }

    /** @return Collection<int, User> */
    public function recipients(string $permission, ?int $alsoUserId = null): Collection
    {
        return User::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('slug', Role::SUPER_ADMIN)
                ->orWhereHas('permissions', fn ($p) => $p->where('slug', $permission)))
                ->when($alsoUserId, fn ($q, $id) => $q->orWhere('id', $id)))
            ->get();
    }
}
