<?php

namespace App\Models;

/** Whether a sending account may be used right now, and why not. Credentials are never stored here. */
class MailAccountHealth extends BaseModel
{
    protected $table = 'mail_account_health';

    protected $attributes = ['consecutive_failures' => 0];

    protected $casts = ['unavailable_until' => 'datetime', 'last_failure_at' => 'datetime', 'last_success_at' => 'datetime'];

    public function isUnavailable(): bool
    {
        return $this->unavailable_until?->isFuture() ?? false;
    }
}
