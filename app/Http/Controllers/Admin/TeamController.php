<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use App\Models\Committee;
use App\Models\Role;
use App\Models\ScrutinyTeam;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Scrutiny teams and Screening Committees, both scoped to an advertisement (and optionally a post). */
class TeamController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function teams(Advertisement $advertisement): JsonResponse
    {
        return response()->json($advertisement->scrutinyTeams()->with(['members:id,name,email', 'post:id,code,name'])->get());
    }

    public function saveTeam(Request $request, Advertisement $advertisement, ?ScrutinyTeam $team = null): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'post_id' => ['nullable', Rule::exists('posts', 'id')->where('advertisement_id', $advertisement->id)],
            'member_ids' => ['present', 'array'],
            'member_ids.*' => ['integer', 'exists:users,id'],
        ]);
        abort_if($team && $team->advertisement_id !== $advertisement->id, 404);
        $members = $this->usersWithRole($data['member_ids'], Role::SCRUTINY_USER);

        $team = DB::transaction(function () use ($advertisement, $team, $data, $members) {
            $team ??= new ScrutinyTeam(['advertisement_id' => $advertisement->id]);
            $old = $team->exists ? ['members' => $team->members()->pluck('users.id')] : [];
            $team->fill(['name' => $data['name'], 'post_id' => $data['post_id'] ?? null])->save();
            $team->members()->sync($members);
            $this->audit->record('scrutiny_team.saved', $team, $old, ['name' => $team->name, 'members' => $members]);

            return $team;
        });

        return response()->json($team->load('members:id,name,email'));
    }

    public function deleteTeam(Advertisement $advertisement, ScrutinyTeam $team): JsonResponse
    {
        abort_unless($team->advertisement_id === $advertisement->id, 404);
        $this->audit->record('scrutiny_team.deleted', $team, ['name' => $team->name]);
        $team->delete();

        return response()->json(['deleted' => true]);
    }

    public function committees(Advertisement $advertisement): JsonResponse
    {
        return response()->json($advertisement->committees()->with(['members:id,name,email', 'post:id,code,name', 'evaluationForm:id,name'])->withCount('assignments')->get());
    }

    public function saveCommittee(Request $request, Advertisement $advertisement, ?Committee $committee = null): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'post_id' => ['nullable', Rule::exists('posts', 'id')->where('advertisement_id', $advertisement->id)],
            'evaluation_mode' => ['nullable', Rule::in(['individual', 'collective'])],
            'evaluation_form_template_id' => ['nullable', 'exists:evaluation_form_templates,id'],
            'members' => ['present', 'array'],
            'members.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'members.*.is_chair' => ['boolean'],
        ]);
        abort_if($committee && $committee->advertisement_id !== $advertisement->id, 404);
        $valid = $this->usersWithRole(collect($data['members'])->pluck('user_id')->all(), Role::COMMITTEE_MEMBER);
        $sync = collect($data['members'])->whereIn('user_id', $valid)
            ->mapWithKeys(fn ($m) => [$m['user_id'] => ['is_chair' => (bool) ($m['is_chair'] ?? false)]])->all();

        $committee = DB::transaction(function () use ($advertisement, $committee, $data, $sync) {
            $committee ??= new Committee(['advertisement_id' => $advertisement->id]);
            $committee->fill(collect($data)->only(['name', 'post_id', 'evaluation_mode', 'evaluation_form_template_id'])->all())->save();
            $committee->members()->sync($sync);
            $this->audit->record('committee.saved', $committee, [], ['name' => $committee->name, 'members' => $sync]);

            return $committee;
        });

        return response()->json($committee->load('members:id,name,email'));
    }

    public function deleteCommittee(Advertisement $advertisement, Committee $committee): JsonResponse
    {
        abort_unless($committee->advertisement_id === $advertisement->id, 404);
        abort_if($committee->assignments()->exists(), 422, 'This committee already has applications assigned.');
        $this->audit->record('committee.deleted', $committee, ['name' => $committee->name]);
        $committee->delete();

        return response()->json(['deleted' => true]);
    }

    private function usersWithRole(array $ids, string $role): array
    {
        return User::whereIn('id', $ids)->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('slug', $role))->pluck('id')->all();
    }
}
