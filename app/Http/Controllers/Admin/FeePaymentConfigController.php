<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use App\Models\FeePaymentConfig;
use App\Models\FeePaymentConfigRevision;
use App\Models\Post;
use App\Services\ApplicationAccess;
use App\Services\FeePaymentConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Administration → Application fee settings (permission payments.configure, limited to managed advertisements). */
class FeePaymentConfigController extends Controller
{
    public function __construct(private FeePaymentConfigService $configs, private ApplicationAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $ads = Advertisement::with(['posts:id,advertisement_id,code,name,fee_schedule'])
            ->where('status', '!=', Advertisement::ARCHIVED)
            ->orderByDesc('id')->get(['id', 'number', 'title', 'status', 'fee_schedule', 'payment_methods'])
            ->filter(fn (Advertisement $a) => $this->access->managesAdvertisement($user, $a->id))->values();

        $configs = FeePaymentConfig::with(['advertisement:id,number,title,fee_schedule', 'post:id,advertisement_id,code,name,fee_schedule', 'updater:id,name'])
            ->withCount('payments')
            ->whereIn('advertisement_id', $ads->pluck('id'))
            ->when($request->integer('advertisement_id'), fn ($q, $id) => $q->where('advertisement_id', $id))
            ->orderByDesc('is_active')->orderByDesc('updated_at')->get();

        return response()->json([
            'configs' => $configs->map(fn (FeePaymentConfig $c) => $this->summary($c)),
            'advertisements' => $ads->map(fn (Advertisement $a) => [
                'id' => $a->id, 'number' => $a->number, 'title' => $a->title, 'status' => $a->status,
                'payment_methods' => $a->payment_methods ?? [], 'fee_schedule' => $a->fee_schedule,
                'posts' => $a->posts->map(fn (Post $p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'fee_schedule' => $p->fee_schedule]),
            ]),
            'account_types' => FeePaymentConfig::ACCOUNT_TYPES,
            'qr_limits' => ['max_kb' => FeePaymentConfigService::QR_MAX_KB, 'min_px' => FeePaymentConfigService::QR_MIN_PX, 'max_px' => FeePaymentConfigService::QR_MAX_PX],
        ]);
    }

    public function show(Request $request, FeePaymentConfig $feePaymentConfig): JsonResponse
    {
        $this->authorizeConfig($request, $feePaymentConfig->advertisement_id);

        return response()->json($this->detail($feePaymentConfig));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);
        $this->authorizeConfig($request, (int) $data['advertisement_id']);
        $config = $this->configs->save($this->fields($data), null, $request->user(), $request->file('qr'), reason: $data['reason'] ?? null);

        return response()->json($this->detail($config), 201);
    }

    /** POST (multipart, for the QR file). */
    public function update(Request $request, FeePaymentConfig $feePaymentConfig): JsonResponse
    {
        $this->authorizeConfig($request, $feePaymentConfig->advertisement_id);
        $data = $this->validated($request, $feePaymentConfig);
        $config = $this->configs->save($this->fields($data), $feePaymentConfig, $request->user(), $request->file('qr'),
            (bool) ($data['remove_qr'] ?? false), (bool) ($data['confirm'] ?? false), $data['reason'] ?? null);

        return response()->json($this->detail($config));
    }

    public function status(Request $request, FeePaymentConfig $feePaymentConfig): JsonResponse
    {
        $this->authorizeConfig($request, $feePaymentConfig->advertisement_id);
        $data = $request->validate(['active' => ['required', 'boolean'], 'confirm' => ['boolean'], 'reason' => ['nullable', 'string', 'max:500']]);
        $config = $this->configs->setActive($feePaymentConfig, (bool) $data['active'], $request->user(), (bool) ($data['confirm'] ?? false), $data['reason'] ?? null);

        return response()->json($this->detail($config));
    }

    public function copy(Request $request, FeePaymentConfig $feePaymentConfig): JsonResponse
    {
        $this->authorizeConfig($request, $feePaymentConfig->advertisement_id);
        $data = $request->validate([
            'advertisement_id' => ['required', 'integer', Rule::exists('advertisements', 'id')->whereNull('deleted_at')],
            'post_id' => ['nullable', 'integer', Rule::exists('posts', 'id')->where('advertisement_id', $request->integer('advertisement_id'))],
        ]);
        $this->authorizeConfig($request, (int) $data['advertisement_id']);
        $copy = $this->configs->copy($feePaymentConfig, (int) $data['advertisement_id'], $data['post_id'] ?? null, $request->user());

        return response()->json($this->detail($copy), 201);
    }

    public function destroy(Request $request, FeePaymentConfig $feePaymentConfig): JsonResponse
    {
        $this->authorizeConfig($request, $feePaymentConfig->advertisement_id);
        $this->configs->delete($feePaymentConfig, $request->user());

        return response()->json(['ok' => true]);
    }

    public function qr(Request $request, FeePaymentConfig $feePaymentConfig): StreamedResponse
    {
        $this->authorizeConfig($request, $feePaymentConfig->advertisement_id);

        return $this->configs->streamQr($feePaymentConfig, $request->user(), download: $request->boolean('download'));
    }

    private function authorizeConfig(Request $request, int $advertisementId): void
    {
        abort_unless($this->access->managesAdvertisement($request->user(), $advertisementId), 403, 'You do not manage this advertisement.');
    }

    private function validated(Request $request, ?FeePaymentConfig $config): array
    {
        $request->merge(array_filter([
            'ifsc' => $request->filled('ifsc') ? strtoupper(trim((string) $request->input('ifsc'))) : null,
            'account_number' => $request->filled('account_number') ? preg_replace('/\s+/', '', (string) $request->input('account_number')) : null,
        ], fn ($v) => $v !== null));
        $adId = $config?->advertisement_id ?? $request->integer('advertisement_id');

        return $request->validate([
            'advertisement_id' => [$config ? 'prohibited' : 'required', 'integer', Rule::exists('advertisements', 'id')->whereNull('deleted_at')],
            'post_id' => [$config ? 'prohibited' : 'nullable', 'integer', Rule::exists('posts', 'id')->where('advertisement_id', $adId)],
            'label' => ['nullable', 'string', 'max:150'],
            'account_holder' => ['nullable', 'string', 'max:200'],
            'bank_name' => ['nullable', 'string', 'max:200'],
            'branch_name' => ['nullable', 'string', 'max:200'],
            'account_number' => ['nullable', 'string', 'regex:/^[0-9]{6,20}$/'],
            'account_type' => ['nullable', Rule::in(array_keys(FeePaymentConfig::ACCOUNT_TYPES))],
            'ifsc' => ['nullable', 'string', 'regex:'.FeePaymentConfigService::IFSC],
            'branch_address' => ['nullable', 'string', 'max:500'],
            'upi_id' => ['nullable', 'string', 'max:100', 'regex:'.FeePaymentConfigService::UPI],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'reference_instructions' => ['nullable', 'string', 'max:2000'],
            'payment_deadline' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'qr' => ['nullable', 'file'],
            'remove_qr' => ['boolean'],
            'confirm' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'account_number.regex' => 'The account number must contain 6 to 20 digits only.',
            'ifsc.regex' => 'The IFSC code must be 11 characters: four letters, a zero, then six letters or digits (e.g. SBIN0001234).',
            'upi_id.regex' => 'The UPI ID must look like name@bank.',
        ]);
    }

    private function fields(array $data): array
    {
        return collect($data)->only(['advertisement_id', 'post_id', 'label', 'account_holder', 'bank_name', 'branch_name', 'account_number',
            'account_type', 'ifsc', 'branch_address', 'upi_id', 'instructions', 'reference_instructions', 'payment_deadline', 'is_active'])->all();
    }

    private function summary(FeePaymentConfig $c): array
    {
        $schedule = $c->post?->fee_schedule ?: ($c->advertisement->fee_schedule ?? null);

        return [
            'id' => $c->id,
            'advertisement' => ['id' => $c->advertisement_id, 'number' => $c->advertisement->number, 'title' => $c->advertisement->title],
            'post' => $c->post ? ['id' => $c->post->id, 'code' => $c->post->code, 'name' => $c->post->name] : null,
            'label' => $c->label,
            'bank_name' => $c->bank_name,
            'account_number_masked' => $c->maskedAccountNumber(),
            'ifsc' => $c->ifsc,
            'upi_id' => $c->upi_id,
            'has_qr' => $c->hasQr(),
            'is_active' => $c->is_active,
            'version' => $c->version,
            'fee_schedule' => $schedule,
            'payments_count' => $c->payments_count ?? $c->payments()->count(),
            'updated_by' => $c->updater?->name,
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    private function detail(FeePaymentConfig $c): array
    {
        $c->loadMissing(['advertisement:id,number,title,fee_schedule,payment_methods', 'post:id,advertisement_id,code,name,fee_schedule', 'updater:id,name']);

        return [
            ...$this->summary($c),
            ...$c->only(['account_holder', 'branch_name', 'account_type', 'branch_address', 'instructions', 'reference_instructions']),
            'account_number' => $c->account_number,
            'payment_deadline' => $c->payment_deadline?->toIso8601String(),
            'payment_methods' => $c->advertisement->payment_methods ?? [],
            'qr' => $c->hasQr() ? ['mime' => $c->qr_mime, 'size' => $c->qr_size, 'width' => $c->qr_width, 'height' => $c->qr_height,
                'available' => Storage::disk($c->qr_disk)->exists($c->qr_path)] : null,
            'activation_problems' => $this->configs->activationProblems($c),
            'affected_applications' => $this->configs->affectedApplications($c),
            'revisions' => $c->revisions()->with('changer:id,name')->get()->map(fn (FeePaymentConfigRevision $r) => [
                'version' => $r->version, 'event' => $r->event, 'reason' => $r->reason,
                'by' => $r->changer?->name, 'at' => $r->created_at?->toIso8601String(),
            ]),
        ];
    }
}
