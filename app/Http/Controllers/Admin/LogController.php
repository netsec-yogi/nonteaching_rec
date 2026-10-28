<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\DocumentAccessLog;
use App\Models\EmailLog;
use App\Models\ExportLog;
use App\Services\ApplicationAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Log viewers. audit.view / email_logs.view grant the screens; what they list is still limited
 * to the applications the viewer may see (plus entries not tied to any application), so a
 * scrutiny officer with audit.view cannot read the trail of another advertisement.
 */
class LogController extends Controller
{
    public function __construct(private ApplicationAccess $access) {}

    public function audit(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('audit.view'), 403);
        $f = $request->validate(['action' => ['nullable', 'string', 'max:80'], 'user_id' => ['nullable', 'integer'], 'actor_type' => ['nullable', 'string', 'max:20'],
            'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date']]);

        return response()->json($this->scoped(AuditLog::with('user:id,name,email'), $request)
            ->when($f['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', addcslashes($a, '%_\\').'%'))
            ->when($f['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($f['actor_type'] ?? null, fn ($q, $t) => $q->where('actor_type', $t))
            ->when($f['date_from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($f['date_to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->orderByDesc('id')->paginate(50));
    }

    public function emails(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('email_logs.view'), 403);
        $f = $request->validate(['status' => ['nullable', 'string', 'max:20'], 'template_key' => ['nullable', 'string', 'max:80'], 'q' => ['nullable', 'string', 'max:100']]);

        return response()->json($this->scoped(EmailLog::with('application:id,uuid,reference_no'), $request)
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['template_key'] ?? null, fn ($q, $k) => $q->where('template_key', $k))
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where('to_email', 'like', '%'.addcslashes($t, '%_\\').'%'))
            ->orderByDesc('id')->paginate(50));
    }

    public function access(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('audit.view'), 403);
        $f = $request->validate(['resource_type' => ['nullable', 'string', 'max:40']]);

        return response()->json($this->scoped(DocumentAccessLog::with('user:id,name,email'), $request)
            ->when($f['resource_type'] ?? null, fn ($q, $t) => $q->where('resource_type', $t))
            ->orderByDesc('id')->paginate(50));
    }

    public function exports(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('audit.view'), 403);

        return response()->json(ExportLog::with('user:id,name,email')
            ->when(! $request->user()->isSuperAdmin(), fn ($q) => $q->where('user_id', $request->user()->id))
            ->orderByDesc('id')->paginate(50));
    }

    /** Entries about applications the viewer may see, plus entries not tied to any application. */
    private function scoped(Builder $query, Request $request): Builder
    {
        $user = $request->user();
        if ($user->isSuperAdmin()) {
            return $query;
        }
        $visible = $this->access->scope(Application::query(), $user)->select('applications.id');

        return $query->where(fn ($q) => $q->whereNull('application_id')->orWhereIn('application_id', $visible));
    }
}
