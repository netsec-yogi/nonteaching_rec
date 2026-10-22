<?php

namespace App\Services;

use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filters shared by the administrative application list, reports and
 * exports. Always starts from the user's access scope.
 */
class ApplicationFilter
{
    public const FILTERS = [
        'advertisement_id', 'cycle_id', 'post_id', 'department', 'q', 'reference_no', 'category', 'gender',
        'date_from', 'date_to', 'status', 'scrutiny_status', 'fee_status', 'referee_status',
        'endorsement_status', 'document_status', 'outcome', 'committee_status', 'submitted_only',
    ];

    private const SORTS = [
        'submitted_at' => 'applications.submitted_at',
        'reference_no' => 'applications.reference_no',
        'created_at' => 'applications.created_at',
        'name' => 'application_personal_details.full_name',
    ];

    public function __construct(private ApplicationAccess $access) {}

    public function query(User $user, array $filters): Builder
    {
        $q = $this->access->scope(Application::query(), $user)->select('applications.*');
        $f = fn (string $k) => isset($filters[$k]) && $filters[$k] !== '' && $filters[$k] !== null ? $filters[$k] : null;

        $q->when($f('advertisement_id'), fn ($q, $v) => $q->where('applications.advertisement_id', $v))
            ->when($f('post_id'), fn ($q, $v) => $q->where('applications.post_id', $v))
            ->when($f('cycle_id'), fn ($q, $v) => $q->whereHas('advertisement', fn ($a) => $a->where('recruitment_cycle_id', $v)))
            ->when($f('department'), fn ($q, $v) => $q->whereHas('post', fn ($p) => $p->where('department', $v)))
            ->when($f('reference_no'), fn ($q, $v) => $q->where('applications.reference_no', 'like', '%'.$v.'%'))
            ->when($f('category'), fn ($q, $v) => $q->whereHas('personal', fn ($p) => $p->where('category', $v)))
            ->when($f('gender'), fn ($q, $v) => $q->whereHas('personal', fn ($p) => $p->where('gender', $v)))
            ->when($f('date_from'), fn ($q, $v) => $q->where('applications.submitted_at', '>=', $v.' 00:00:00'))
            ->when($f('date_to'), fn ($q, $v) => $q->where('applications.submitted_at', '<=', $v.' 23:59:59'));

        foreach (['status', 'scrutiny_status', 'fee_status', 'referee_status', 'endorsement_status', 'document_status', 'outcome', 'committee_status'] as $col) {
            $q->when($f($col), fn ($q, $v) => $q->whereIn("applications.$col", (array) $v));
        }

        if ($f('submitted_only')) {
            $q->whereNotNull('applications.submitted_at');
        }

        if ($term = $f('q')) {
            $q->where(function ($w) use ($term) {
                $w->where('applications.reference_no', 'like', "%$term%")
                    ->orWhereHas('personal', fn ($p) => $p->where('full_name', 'like', "%$term%")->orWhere('email', 'like', "%$term%")->orWhere('mobile', 'like', "%$term%"));
            });
        }

        return $q;
    }

    public function sorted(Builder $q, ?string $sort, ?string $direction): Builder
    {
        $column = self::SORTS[$sort ?? 'submitted_at'] ?? self::SORTS['submitted_at'];
        $direction = $direction === 'asc' ? 'asc' : 'desc';
        if (str_starts_with($column, 'application_personal_details')) {
            $q->leftJoin('application_personal_details', 'application_personal_details.application_id', '=', 'applications.id');
        }

        return $q->orderBy($column, $direction)->orderBy('applications.id', $direction);
    }
}
