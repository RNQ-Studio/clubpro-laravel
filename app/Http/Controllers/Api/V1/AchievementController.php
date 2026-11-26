<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AchievementRequest;
use App\Models\AchievementEntry;
use App\Models\AchievementRecord;
use App\Models\AchievementRule;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\MemberIdentity;
use App\Models\User;
use App\Services\AchievementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AchievementController extends Controller
{
    public function __construct(private readonly AchievementService $points) {}

    public function options(): JsonResponse
    {
        return ApiResponse::success($this->points->options());
    }

    public function rules(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        abort_unless($user->is_active && $user->hasRole('super-admin'), 403);

        return ApiResponse::success([...$this->points->options(), 'history' => AchievementRule::query()->orderByDesc('version')->get()->map(fn (AchievementRule $rule) => ['version' => $rule->version, 'points' => $rule->points, 'age_boundaries' => $rule->age_boundaries, 'effective_at' => $rule->effective_at->toIso8601String(), 'changed_by' => $rule->changed_by, 'reason' => $rule->reason])->all()]);
    }

    public function configure(AchievementRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['expected_version'] = $request->integer('expected_version');
        $data['points'] = array_map('intval', $data['points']);
        $data['age_boundaries'] = array_map('intval', $data['age_boundaries']);
        $this->points->configure($this->actor($request), $data);

        return $this->rules($request);
    }

    public function rankings(Request $request): JsonResponse
    {
        $filters = $request->validate(['scope' => ['nullable', Rule::in(['members', 'communities'])], 'community_id' => ['nullable', 'integer', 'min:1'], 'period' => ['nullable', Rule::in(['month', 'all'])], 'month' => ['nullable', 'date_format:Y-m'], 'as_of' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'gender' => ['nullable', Rule::in(['male', 'female', 'unknown'])], 'age_group' => ['nullable', Rule::in(['u12', '12_15', '16_18', '19_35', '36_50', '51_plus', 'unknown'])], 'page' => ['integer', 'min:1'], 'per_page' => ['integer', 'min:1', 'max:100']]);

        return ApiResponse::success($this->points->rankings($filters));
    }

    public function own(Request $request): JsonResponse
    {
        $request->validate(['page' => ['integer', 'min:1'], 'per_page' => ['integer', 'min:1', 'max:100'], 'community_id' => ['nullable', 'integer', 'min:1']]);
        $identity = MemberIdentity::forUser($this->actor($request));
        $query = AchievementEntry::query()->whereIn('member_identity_id', $this->points->identityIds($identity))->when($request->filled('community_id'), fn ($q) => $q->where('community_id', $request->integer('community_id')));
        $total = (int) (clone $query)->sum('points');
        $page = $query->latest('created_at')->orderByDesc('id')->paginate($request->integer('per_page', 20));

        return ApiResponse::success(['total_points' => $total, 'items' => collect($page->items())->map(fn (AchievementEntry $entry) => ['id' => $entry->id, 'type' => $entry->type, 'points' => $entry->points, 'community' => ['id' => $entry->community_id, 'name' => Community::query()->findOrFail($entry->community_id)->name], 'source_id' => $entry->source_id, 'actor_id' => $entry->actor_id, 'created_by' => $entry->actor_id, 'actor_name' => $entry->actor_id === null ? null : User::query()->find($entry->actor_id)?->name, 'rule_version' => $entry->rule_version, 'occurred_at' => $entry->occurred_at->toIso8601String(), 'created_at' => $entry->created_at->toIso8601String()])->all(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    public function members(Request $request, Community $community): JsonResponse
    {
        $request->validate(['page' => ['integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100']]);
        abort_unless($this->points->canManage($this->actor($request), $community), 403);
        $page = CommunityMember::query()->with('identity')->where('community_id', $community->id)->where('status', 'active')->whereNull('merged_into_member_id')->whereHas('identity', fn ($q) => $q->whereNull('merged_into_id')->when($request->filled('search'), fn ($q) => $q->whereRaw('lower(name) like ?', ['%'.addcslashes(mb_strtolower(trim((string) $request->query('search'))), '\\%_').'%'])))->orderBy('id')->paginate(100);

        return ApiResponse::success(['items' => collect($page->items())->map(fn ($member) => ['id' => $member->id, 'name' => $member->identity->name, 'role' => $member->role->value])->all(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    public function records(Request $request, Community $community): JsonResponse
    {
        $request->validate(['page' => ['integer', 'min:1']]);
        $actor = $this->actor($request);
        abort_unless($this->points->canManage($actor, $community), 403);
        $page = AchievementRecord::query()->where('community_id', $community->id)->latest()->orderByDesc('id')->paginate(20);

        return ApiResponse::success(['items' => collect($page->items())->map(fn (AchievementRecord $record) => $this->points->recordData($record))->all(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'can_manage' => true, 'can_correct' => $this->points->canManage($actor, $community, true)]);
    }

    public function store(AchievementRequest $request, Community $community): JsonResponse
    {
        return ApiResponse::success($this->points->recordData($this->points->record($this->actor($request), $community, $request->validated())), 'Prestasi dicatat.', 201);
    }

    public function correct(AchievementRequest $request, Community $community, AchievementRecord $achievement): JsonResponse
    {
        return ApiResponse::success($this->points->recordData($this->points->correct($this->actor($request), $community, $achievement, $request->validated())), 'Prestasi dikoreksi.');
    }

    private function actor(Request $request): User
    {/** @var User $actor */ $actor = $request->user();

        return $actor;
    }
}
