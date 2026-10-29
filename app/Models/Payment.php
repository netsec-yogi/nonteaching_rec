<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends BaseModel
{
    public const METHODS = ['neft' => 'NEFT / RTGS', 'upi' => 'UPI / QR code', 'demand_draft' => 'Demand Draft', 'online' => 'Online payment', 'exempt' => 'Fee exempted'];

    /** Methods a candidate can choose (configured per advertisement). */
    public const PAYABLE_METHODS = ['neft', 'upi', 'demand_draft', 'online'];

    protected $attributes = ['status' => 'pending_verification'];

    protected $hidden = ['payment_config_snapshot'];

    protected $casts = [
        'payment_config_snapshot' => 'encrypted:array',
        'transaction_date' => 'date',
        'verified_at' => 'datetime',
        'amount' => 'decimal:2',
        'amount_due' => 'decimal:2',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(PaymentVerification::class)->orderByDesc('id');
    }

    public function feePaymentConfig(): BelongsTo
    {
        return $this->belongsTo(FeePaymentConfig::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
