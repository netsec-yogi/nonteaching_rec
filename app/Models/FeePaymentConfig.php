<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bank details and QR code applicants pay the application fee to, for one advertisement
 * (post_id null) or one post. Changed only through FeePaymentConfigService, which keeps
 * the revision history.
 */
class FeePaymentConfig extends BaseModel
{
    public const ACCOUNT_TYPES = ['savings' => 'Savings', 'current' => 'Current'];

    /** What an applicant is shown, and what is frozen with their payment. */
    public const APPLICANT_FIELDS = ['account_holder', 'bank_name', 'branch_name', 'account_number', 'account_type', 'ifsc',
        'branch_address', 'upi_id', 'instructions', 'reference_instructions', 'payment_deadline'];

    protected $hidden = ['qr_disk', 'qr_path', 'account_number'];

    protected $attributes = ['is_active' => false, 'version' => 1];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'payment_deadline' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function advertisement(): BelongsTo
    {
        return $this->belongsTo(Advertisement::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(FeePaymentConfigRevision::class)->orderByDesc('version');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function hasQr(): bool
    {
        return $this->qr_path !== null;
    }

    public function maskedAccountNumber(): ?string
    {
        $n = $this->account_number;

        return $n === null || $n === '' ? null : str_repeat('•', max(0, strlen($n) - 4)).substr($n, -4);
    }

    /** The applicant-facing details (without the QR file reference). */
    public function applicantDetails(): array
    {
        return [
            ...$this->only(array_diff(self::APPLICANT_FIELDS, ['payment_deadline', 'account_number'])),
            'account_number' => $this->account_number,
            'payment_deadline' => $this->payment_deadline?->toIso8601String(),
        ];
    }
}
