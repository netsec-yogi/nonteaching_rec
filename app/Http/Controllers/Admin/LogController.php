<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentAccessLog;
use App\Models\EmailLog;
use App\Models\ExportLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function audit(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('audit.view'), 403);

        return response()->json(AuditLog::with('user:id,name,email')
            ->when($request->action, fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($request->user_id, fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->actor_type, fn ($q, $t) => $q->where('actor_type', $t))
            ->when($request->date_from, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->date_to, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->orderByDesc('id')->paginate(50));
    }

    public function emails(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('email_logs.view'), 403);

        return response()->json(EmailLog::with('application:id,uuid,reference_no')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->template_key, fn ($q, $k) => $q->where('template_key', $k))
            ->when($request->q, fn ($q, $t) => $q->where('to_email', 'like', "%$t%"))
            ->orderByDesc('id')->paginate(50));
    }

    public function access(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('audit.view'), 403);

        return response()->json(DocumentAccessLog::with('user:id,name,email')
            ->when($request->resource_type, fn ($q, $t) => $q->where('resource_type', $t))
            ->orderByDesc('id')->paginate(50));
    }

    public function exports(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('audit.view'), 403);

        return response()->json(ExportLog::with('user:id,name,email')->orderByDesc('id')->paginate(50));
    }
}
