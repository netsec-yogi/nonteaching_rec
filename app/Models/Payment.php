<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends BaseModel
{
    public const METHODS = ['neft' => 'NEFT / RTGS', 'demand_draft' => 'Demand Draft', 'online' => 'Online payment', 'exempt' => 'Fee exempted'];

    protected $attributes = ['status' => 'pending_verification'];

    protected $casts = [
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

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
