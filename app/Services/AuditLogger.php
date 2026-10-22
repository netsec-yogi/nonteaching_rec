<?php

namespace App\Services;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only writer of audit_logs. Entries are evidence: in tests a failed write
 * is rethrown so a silently dropped entry cannot go unnoticed; in production it
 * is logged rather than breaking the user's request.
 */
class AuditLogger
{
    /** Attributes never copied into an audit entry. */
    private const REDACT = ['password', 'remember_token', 'token_hash', 'verification_code_hash', 'path', 'disk', 'stored_name'];

    public function record(
        string $action,
        ?Model $auditable = null,
        array $old = [],
        array $new = [],
        array $meta = [],
        ?Application $application = null,
        ?User $user = null,
        string $actorType = 'user',
        ?string $actorLabel = null,
    ): ?AuditLog {
        try {
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
            $user ??= $request?->user();

            $applicationId = $application?->id
                ?? ($auditable instanceof Application ? $auditable->id : $auditable?->getAttribute('application_id'));

            return AuditLog::create([
                'user_id' => $user?->id,
                'actor_type' => $user ? 'user' : $actorType,
                'actor_label' => $actorLabel ?? $user?->email,
                'action' => $action,
                'auditable_type' => $auditable?->getMorphClass(),
                'auditable_id' => $auditable?->getKey(),
                'application_id' => $applicationId,
                'old_values' => $this->clean($old) ?: null,
                'new_values' => $this->clean($new) ?: null,
                'meta' => $meta ?: null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? substr((string) $request->userAgent(), 0, 500) : null,
            ]);
        } catch (Throwable $e) {
            if (app()->runningUnitTests()) {
                throw $e;
            }
            Log::critical('Audit log write failed', ['action' => $action, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Record the dirty attributes of a model that is about to be (or was just) saved. */
    public function recordChanges(string $action, Model $model, array $meta = [], ?Application $application = null): ?AuditLog
    {
        $changes = $model->getChanges() ?: $model->getDirty();
        unset($changes['updated_at']);
        if (! $changes) {
            return null;
        }
        $old = array_intersect_key($model->getOriginal(), $changes);

        return $this->record($action, $model, $old, $changes, $meta, $application);
    }

    private function clean(array $values): array
    {
        foreach (self::REDACT as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }

        return array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('c') : $v, $values);
    }
}
