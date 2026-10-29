<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Services\ApplicationFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Application fee verification list. Rows are the applications the user can see (ApplicationAccess),
 * with the fee shown in one of these display statuses, derived from the stored payment row:
 * not_required, pending_payment, details_submitted (draft), pending_verification, verified, rejected, exempt.
 */
class PaymentVerificationController extends Controller
{
    public const STATUSES = ['pending_verification', 'details_submitted', 'pending_payment', 'verified', 'rejected', 'exempt', 'not_required'];

    public function __construct(private ApplicationFilter $filter) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('applications.verify'), 403);
        $status = in_array($request->input('status'), self::STATUSES, true) ? $request->input('status') : null;
        $base = fn () => $this->filter->query($request->user(), $request->only(['advertisement_id', 'post_id', 'q']));

        $counts = collect(self::STATUSES)->mapWithKeys(fn ($s) => [$s => $this->constrain($base(), $s)->count()]);
        $q = $this->constrain($base(), $status)
            ->with(['personal:id,application_id,full_name', 'post:id,code,name', 'advertisement:id,number', 'payment.verifier:id,name',
                'documents' => fn ($d) => $d->whereHas('documentType', fn ($t) => $t->where('step', 'payment'))->select(['id', 'uuid', 'application_id', 'document_type_id', 'original_name'])])
            ->orderByRaw('applications.submitted_at is null')->orderByDesc('applications.submitted_at')->orderByDesc('applications.id');
        $page = $q->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (Application $a) => $this->row($a)),
            'counts' => $counts,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public static function displayStatus(Application $a): string
    {
        $p = $a->payment;

        return match (true) {
            $p === null => 'pending_payment',
            $p->status === 'exempt' => $p->exemption_basis ? 'exempt' : 'not_required',
            $p->status === 'pending_verification' => $a->submitted_at ? 'pending_verification' : 'details_submitted',
            default => $p->status,
        };
    }

    private function constrain(Builder $q, ?string $status): Builder
    {
        $payment = fn (callable $where) => $q->whereHas('payment', $where);

        return match ($status) {
            null => $q,
            'pending_payment' => $q->whereDoesntHave('payment'),
            'not_required' => $payment(fn ($p) => $p->where('status', 'exempt')->whereNull('exemption_basis')),
            'exempt' => $payment(fn ($p) => $p->where('status', 'exempt')->whereNotNull('exemption_basis')),
            'details_submitted' => $payment(fn ($p) => $p->where('status', 'pending_verification'))->whereNull('applications.submitted_at'),
            'pending_verification' => $payment(fn ($p) => $p->where('status', 'pending_verification'))->whereNotNull('applications.submitted_at'),
            default => $payment(fn ($p) => $p->where('status', $status)),
        };
    }

    private function row(Application $a): array
    {
        $p = $a->payment;

        return [
            'uuid' => $a->uuid,
            'reference_no' => $a->reference_no,
            'name' => $a->personal?->full_name,
            'advertisement' => $a->advertisement->number,
            'post' => $a->post->name,
            'post_code' => $a->post->code,
            'application_status' => $a->status,
            'submitted_at' => $a->submitted_at?->toIso8601String(),
            'fee_status' => self::displayStatus($a),
            'amount_due' => $p ? (float) $p->amount_due : null,
            'amount' => $p && $p->status !== 'exempt' ? (float) $p->amount : null,
            'discrepancy' => $p && $p->status !== 'exempt' && round((float) $p->amount - (float) $p->amount_due, 2) !== 0.0,
            'method' => $p?->method,
            'utr_number' => $p?->utr_number,
            'transaction_date' => $p?->transaction_date?->toDateString(),
            'bank_name' => $p?->bank_name,
            'config_version' => $p?->fee_payment_config_version,
            'remarks' => $p?->remarks,
            'verified_by' => $p?->verifier?->name,
            'verified_at' => $p?->verified_at?->toIso8601String(),
            'receipts' => $a->documents->map(fn (ApplicationDocument $d) => ['uuid' => $d->uuid, 'name' => $d->original_name])->values(),
        ];
    }
}
