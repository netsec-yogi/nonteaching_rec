<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use App\Models\Application;
use App\Models\EmailLog;
use App\Models\RefereeInvitation;
use App\Services\ApplicationFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ApplicationFilter $filter): JsonResponse
    {
        $user = $request->user();
        $filters = $request->only(['advertisement_id']);
        $q = fn () => $filter->query($user, $filters);
        $submitted = fn () => $q()->whereNotNull('applications.submitted_at')->where('applications.status', '!=', Application::WITHDRAWN);
        $ids = fn () => $submitted()->pluck('applications.id');

        return response()->json([
            'cards' => [
                'drafts' => $q()->where('applications.status', Application::DRAFT)->count(),
                'submitted' => $submitted()->count(),
                'submitted_today' => $submitted()->whereDate('applications.submitted_at', today())->count(),
                'fee_pending' => $submitted()->where('applications.fee_status', 'pending_verification')->count(),
                'scrutiny_pending' => $submitted()->whereNotIn('applications.scrutiny_status', ['decided'])->count(),
                'clarification_open' => $submitted()->whereIn('applications.scrutiny_status', ['clarification_required', 'clarification_received'])->count(),
                'referee_complete' => $submitted()->where('applications.referee_status', 'complete')->count(),
                'referee_pending' => $submitted()->whereIn('applications.referee_status', ['pending', 'partial'])->count(),
                'referee_delivery_failed' => RefereeInvitation::whereIn('application_id', $ids())->where('state', 'active')->where('delivery_status', 'failed')->count(),
                'recommended' => $submitted()->where('applications.outcome', 'recommended')->count(),
                'with_committee' => $submitted()->whereNotNull('applications.committee_status')->count(),
                'emails_failed' => $user->hasPermission('email_logs.view') ? EmailLog::where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count() : null,
            ],
            'by_scrutiny_status' => $submitted()->reorder()->select(DB::raw('applications.scrutiny_status as k'), DB::raw('COUNT(*) as n'))->groupBy('applications.scrutiny_status')->pluck('n', 'k'),
            'by_post' => $submitted()->reorder()->join('posts', 'posts.id', '=', 'applications.post_id')
                ->select(DB::raw('posts.name as k'), DB::raw('COUNT(*) as n'))->groupBy('posts.name')->orderByDesc('n')->limit(10)->pluck('n', 'k'),
            'daily' => $submitted()->reorder()->where('applications.submitted_at', '>=', now()->subDays(29)->startOfDay())
                ->select(DB::raw('DATE(applications.submitted_at) as k'), DB::raw('COUNT(*) as n'))->groupBy('k')->orderBy('k')->pluck('n', 'k'),
            'advertisements' => Advertisement::whereIn('status', [Advertisement::PUBLISHED, Advertisement::SUSPENDED])
                ->orderBy('closes_at')->get(['id', 'number', 'title', 'status', 'closes_at']),
            'notifications' => $user->unreadNotifications()->limit(10)->get(['id', 'data', 'created_at']),
        ]);
    }
}
