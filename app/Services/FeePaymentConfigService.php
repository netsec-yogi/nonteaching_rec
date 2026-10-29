<?php

namespace App\Services;

use App\Models\Application;
use App\Models\FeePaymentConfig;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only writer of fee payment configurations. Every change increases the version and is
 * kept as an append-only revision; account numbers appear in the audit trail masked. A
 * configuration's advertisement/post never changes after creation (copy it instead), so
 * editing one advertisement's bank details cannot affect another's.
 */
class FeePaymentConfigService
{
    public const IFSC = '/^[A-Z]{4}0[A-Z0-9]{6}$/';

    public const UPI = '/^[A-Za-z0-9._-]{2,256}@[A-Za-z][A-Za-z0-9.-]{1,63}$/';

    private const QR_MIMES = ['image/png' => ["\x89PNG\r\n\x1A\n"], 'image/jpeg' => ["\xFF\xD8\xFF"]];

    public const QR_MAX_KB = 2048;

    public const QR_MIN_PX = 200;

    public const QR_MAX_PX = 4000;

    public function __construct(private AuditLogger $audit, private DocumentService $documents, private FeeCalculator $fees) {}

    /**
     * The payment details panel the applicant sees, or null when no fee is payable. A draft shows the
     * currently applicable configuration; a submitted application shows the details its payment was
     * made against (the QR only while it is still the same image).
     */
    public function panelFor(Application $application): ?array
    {
        if ($this->fees->amountDue($application) <= 0) {
            return null;
        }
        $application->loadMissing('payment');
        $snapshot = $application->isEditableByApplicant() ? null : $application->payment?->payment_config_snapshot;
        if ($snapshot) {
            $config = $this->qrConfigFor($application);

            return [...collect($snapshot)->except(['qr_sha256'])->all(), 'has_qr' => $config !== null, 'source' => 'as_paid'];
        }
        $config = $this->forApplication($application);

        return $config ? [...$config->applicantDetails(), 'version' => $config->version, 'has_qr' => $config->hasQr(), 'source' => 'current'] : null;
    }

    /** The configuration whose QR code this applicant may open (see panelFor). */
    public function qrConfigFor(Application $application): ?FeePaymentConfig
    {
        $application->loadMissing('payment');
        $snapshot = $application->isEditableByApplicant() ? null : $application->payment?->payment_config_snapshot;
        if ($snapshot) {
            $config = $application->payment->fee_payment_config_id ? FeePaymentConfig::find($application->payment->fee_payment_config_id) : null;

            return $config && $config->hasQr() && $config->qr_sha256 === ($snapshot['qr_sha256'] ?? null) ? $config : null;
        }
        $config = $this->forApplication($application);

        return $config?->hasQr() ? $config : null;
    }

    /** The active configuration that applies to an application: post-specific first, then advertisement-wide. */
    public function forApplication(Application $application): ?FeePaymentConfig
    {
        return $this->forScope($application->advertisement_id, $application->post_id);
    }

    public function forScope(int $advertisementId, ?int $postId): ?FeePaymentConfig
    {
        $active = FeePaymentConfig::where('advertisement_id', $advertisementId)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('post_id')->when($postId, fn ($q) => $q->orWhere('post_id', $postId)))
            ->get();

        return $active->firstWhere('post_id', $postId) ?? $active->firstWhere('post_id', null);
    }

    /** What is frozen with a payment: the details the candidate was shown. */
    public function snapshot(FeePaymentConfig $config): array
    {
        return [...$config->applicantDetails(), 'version' => $config->version, 'qr_sha256' => $config->qr_sha256];
    }

    /** @return list<string> Reasons the configuration cannot be activated. */
    public function activationProblems(FeePaymentConfig $config): array
    {
        $config->loadMissing(['advertisement', 'post']);
        $problems = [];
        foreach (['account_holder' => 'account holder name', 'bank_name' => 'bank name', 'branch_name' => 'branch name', 'account_number' => 'account number', 'ifsc' => 'IFSC code'] as $field => $label) {
            if (blank($config->{$field})) {
                $problems[] = "Enter the $label.";
            }
        }
        if ($config->ifsc && ! preg_match(self::IFSC, $config->ifsc)) {
            $problems[] = 'The IFSC code is not valid.';
        }
        $methods = $config->advertisement->payment_methods ?? [];
        if (in_array('upi', $methods, true) && blank($config->upi_id) && ! $config->hasQr()) {
            $problems[] = 'The advertisement accepts UPI: enter the UPI ID or upload the bank QR code.';
        }
        if ($config->post_id && $config->post?->advertisement_id !== $config->advertisement_id) {
            $problems[] = 'The post does not belong to this advertisement.';
        }

        return $problems;
    }

    /** Number of live applications that would see a change to this configuration. */
    public function affectedApplications(FeePaymentConfig $config): int
    {
        return Application::where('advertisement_id', $config->advertisement_id)
            ->when($config->post_id, fn ($q) => $q->where('post_id', $config->post_id))
            ->where('status', '!=', Application::WITHDRAWN)
            ->count();
    }

    /**
     * Create or edit. Edits of an active configuration that applications already depend on need
     * `$confirmed` (the UI shows how many applications are affected first).
     */
    public function save(array $data, ?FeePaymentConfig $config, User $actor, ?UploadedFile $qr = null, bool $removeQr = false, bool $confirmed = false, ?string $reason = null): FeePaymentConfig
    {
        $creating = $config === null;
        $config ??= new FeePaymentConfig([
            'advertisement_id' => $data['advertisement_id'],
            'post_id' => $data['post_id'] ?? null,
            'created_by' => $actor->id,
        ]);
        unset($data['advertisement_id'], $data['post_id']);
        $activate = (bool) ($data['is_active'] ?? $config->is_active);
        unset($data['is_active']);

        if ($qr) {
            $this->assertQrAcceptable($qr);
        }

        $stored = null;
        try {
            return $this->persist($config, $data, $actor, $qr, $removeQr, $confirmed, $reason, $creating, $activate, $stored);
        } catch (\Throwable $e) {
            // Nothing was saved: do not leave the newly uploaded image behind.
            if ($stored) {
                Storage::disk($stored['qr_disk'])->delete($stored['qr_path']);
            }
            throw $e;
        }
    }

    private function persist(FeePaymentConfig $config, array $data, User $actor, ?UploadedFile $qr, bool $removeQr, bool $confirmed, ?string $reason, bool $creating, bool $activate, ?array &$stored): FeePaymentConfig
    {
        return DB::transaction(function () use ($config, $data, $actor, $qr, $removeQr, $confirmed, $reason, $creating, $activate, &$stored) {
            if (! $creating) {
                $config = FeePaymentConfig::whereKey($config->id)->lockForUpdate()->firstOrFail();
            }
            $before = $creating ? [] : $this->auditView($config);
            $oldQr = $config->only(['qr_disk', 'qr_path']);

            $config->fill($data);
            if ($qr) {
                $config->fill($stored = $this->storeQr($qr));
            } elseif ($removeQr) {
                $config->fill(['qr_disk' => null, 'qr_path' => null, 'qr_mime' => null, 'qr_size' => null, 'qr_width' => null, 'qr_height' => null, 'qr_sha256' => null]);
            }

            $changed = $creating || $config->isDirty() || $activate !== $config->is_active;
            if (! $changed) {
                return $config;
            }
            $replacing = $activate && ! $config->is_active && $this->forScopeExact($config) !== null;
            if (! $confirmed && ($config->is_active || $replacing) && ($n = $this->affectedApplications($config)) > 0) {
                throw ValidationException::withMessages(['confirm' => $replacing
                    ? "Another configuration is active for this scope and $n application(s) use it. Confirm to replace it: applicants will see these details, and payments already entered keep the details they were made against."
                    : "This configuration is active and $n application(s) use it. Confirm the change: applicants will see the new details, and payments already entered keep the details they were made against."]);
            }

            if ($activate && ! $config->is_active) {
                $this->assertActivatable($config);
                $this->deactivateOthers($config, $actor);
            } elseif ($activate) {
                $this->assertActivatable($config);
            }

            $config->is_active = $activate;
            $config->updated_by = $actor->id;
            if (! $creating) {
                $config->version++;
            }
            $config->save();

            $event = $creating ? 'created' : ($config->wasChanged('is_active') ? ($activate ? 'activated' : 'deactivated') : 'updated');
            $this->revise($config, $event, $actor, $reason);
            $this->audit->record("fee_payment_config.$event", $config, $before, $this->auditView($config), ['version' => $config->version, 'reason' => $reason]);

            if (($qr || $removeQr) && $oldQr['qr_path'] && $oldQr['qr_path'] !== $config->qr_path) {
                DB::afterCommit(fn () => Storage::disk($oldQr['qr_disk'])->delete($oldQr['qr_path']));
            }

            return $config;
        });
    }

    public function setActive(FeePaymentConfig $config, bool $active, User $actor, bool $confirmed = false, ?string $reason = null): FeePaymentConfig
    {
        return $this->save(['is_active' => $active], $config, $actor, confirmed: $confirmed, reason: $reason);
    }

    /** Reuse a configuration for another advertisement or post: an independent, inactive copy. */
    public function copy(FeePaymentConfig $source, int $advertisementId, ?int $postId, User $actor): FeePaymentConfig
    {
        $data = [
            'advertisement_id' => $advertisementId,
            'post_id' => $postId,
            'label' => $source->label,
            ...$source->only(['account_holder', 'bank_name', 'branch_name', 'account_type', 'ifsc', 'branch_address', 'upi_id', 'instructions', 'reference_instructions']),
            'account_number' => $source->account_number,
            'is_active' => false,
        ];

        return DB::transaction(function () use ($source, $data, $actor) {
            $copy = $this->save($data, null, $actor, reason: "Copied from configuration #{$source->id} (version {$source->version})");
            if ($source->hasQr() && Storage::disk($source->qr_disk)->exists($source->qr_path)) {
                $path = 'payment-configs/'.Str::random(40).'.'.($source->qr_mime === 'image/png' ? 'png' : 'jpg');
                Storage::disk($this->documents->disk())->put($path, Storage::disk($source->qr_disk)->get($source->qr_path));
                $copy->forceFill(['qr_disk' => $this->documents->disk(), 'qr_path' => $path, ...$source->only(['qr_mime', 'qr_size', 'qr_width', 'qr_height', 'qr_sha256'])])->save();
            }

            return $copy;
        });
    }

    /** Only a configuration nobody has paid against, and that is not active, can be deleted. */
    public function delete(FeePaymentConfig $config, User $actor): void
    {
        if ($config->is_active) {
            throw ValidationException::withMessages(['config' => 'Deactivate the configuration before deleting it.']);
        }
        if ($config->payments()->exists()) {
            throw ValidationException::withMessages(['config' => 'Payments have been made against this configuration; it is kept as a record. Deactivate it instead.']);
        }

        DB::transaction(function () use ($config) {
            $this->audit->record('fee_payment_config.deleted', $config, $this->auditView($config));
            $file = $config->only(['qr_disk', 'qr_path']);
            $config->revisions()->delete();
            $config->delete();
            if ($file['qr_path']) {
                DB::afterCommit(fn () => Storage::disk($file['qr_disk'])->delete($file['qr_path']));
            }
        });
    }

    public function streamQr(FeePaymentConfig $config, ?User $user, ?int $applicationId = null, bool $download = false): StreamedResponse
    {
        abort_unless($config->hasQr(), 404, 'No QR code is configured.');
        $this->documents->logAccess($user, 'fee_payment_qr', $config->id, $applicationId, $download ? 'download' : 'view');
        $name = 'fee-payment-qr-'.Str::slug($config->advertisement?->number ?? (string) $config->id).'.'.($config->qr_mime === 'image/png' ? 'png' : 'jpg');
        $response = $this->documents->stream($config->qr_disk, $config->qr_path, $name, $config->qr_mime, ! $download);
        // An image, not a document: allow it to render in an <img>, still not cached by shared caches.
        $response->headers->remove('Content-Security-Policy');

        return $response;
    }

    /** The other active configuration with exactly this advertisement/post, if any. */
    private function forScopeExact(FeePaymentConfig $config): ?FeePaymentConfig
    {
        return FeePaymentConfig::where('advertisement_id', $config->advertisement_id)->where('post_id', $config->post_id)
            ->where('is_active', true)->when($config->exists, fn ($q) => $q->whereKeyNot($config->id))->first();
    }

    private function assertActivatable(FeePaymentConfig $config): void
    {
        if ($problems = $this->activationProblems($config)) {
            throw ValidationException::withMessages(['is_active' => $problems]);
        }
    }

    /** One active configuration per advertisement/post: activating one deactivates its predecessor. */
    private function deactivateOthers(FeePaymentConfig $config, User $actor): void
    {
        FeePaymentConfig::where('advertisement_id', $config->advertisement_id)
            ->where('post_id', $config->post_id)
            ->where('is_active', true)
            ->when($config->exists, fn ($q) => $q->whereKeyNot($config->id))
            ->lockForUpdate()->get()
            ->each(function (FeePaymentConfig $other) use ($actor) {
                $other->forceFill(['is_active' => false, 'updated_by' => $actor->id, 'version' => $other->version + 1])->save();
                $this->revise($other, 'deactivated', $actor, 'Replaced by another active configuration');
                $this->audit->record('fee_payment_config.deactivated', $other, ['is_active' => true], ['is_active' => false], ['version' => $other->version, 'reason' => 'Replaced by another active configuration']);
            });
    }

    private function revise(FeePaymentConfig $config, string $event, User $actor, ?string $reason): void
    {
        $config->revisions()->create([
            'version' => $config->version,
            'event' => $event,
            'snapshot' => [...$this->snapshot($config), 'label' => $config->label, 'is_active' => $config->is_active, 'qr' => $config->hasQr()],
            'reason' => $reason,
            'changed_by' => $actor->id,
        ]);
    }

    /** Audit-trail view: account number masked, QR described by its hash. */
    private function auditView(FeePaymentConfig $config): array
    {
        return [
            ...$config->only(['advertisement_id', 'post_id', 'label', 'account_holder', 'bank_name', 'branch_name', 'account_type', 'ifsc', 'branch_address', 'upi_id', 'instructions', 'reference_instructions', 'is_active', 'version']),
            'account_number' => $config->maskedAccountNumber(),
            'payment_deadline' => $config->payment_deadline?->toIso8601String(),
            'qr_sha256' => $config->qr_sha256,
        ];
    }

    private function assertQrAcceptable(UploadedFile $file): void
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['qr' => $m]);
        if (! $file->isValid()) {
            $fail('The QR code image could not be uploaded.');
        }
        if ($file->getSize() > self::QR_MAX_KB * 1024) {
            $fail('The QR code image must not be larger than 2 MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: '';
        if (! isset(self::QR_MIMES[$mime])) {
            $fail('The QR code must be a PNG or JPEG image.');
        }
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 16);
        if (! collect(self::QR_MIMES[$mime])->contains(fn ($sig) => str_starts_with($head, $sig))) {
            $fail('The file content does not match a PNG or JPEG image.');
        }
        $size = @getimagesize($file->getRealPath());
        if (! $size) {
            $fail('The QR code image could not be read.');
        }
        [$w, $h] = $size;
        if (min($w, $h) < self::QR_MIN_PX) {
            $fail('The QR code image is too small to scan reliably: it must be at least '.self::QR_MIN_PX.' × '.self::QR_MIN_PX.' pixels.');
        }
        if (max($w, $h) > self::QR_MAX_PX) {
            $fail('The QR code image must not be larger than '.self::QR_MAX_PX.' pixels on either side.');
        }
    }

    /** Stored exactly as uploaded (no resizing or re-encoding, which could make the code unreadable). */
    private function storeQr(UploadedFile $file): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        [$w, $h] = getimagesize($file->getRealPath());
        $path = 'payment-configs/'.Str::random(40).'.'.($mime === 'image/png' ? 'png' : 'jpg');
        Storage::disk($this->documents->disk())->put($path, file_get_contents($file->getRealPath()));

        return [
            'qr_disk' => $this->documents->disk(), 'qr_path' => $path, 'qr_mime' => $mime, 'qr_size' => $file->getSize(),
            'qr_width' => $w, 'qr_height' => $h, 'qr_sha256' => hash_file('sha256', $file->getRealPath()),
        ];
    }
}
