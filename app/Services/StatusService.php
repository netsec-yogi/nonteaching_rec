<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationStatusHistory;

/**
 * Changes one of an application's status fields and records the change in
 * the status history and the audit trail. Callers own the transaction.
 */
class StatusService
{
    public function __construct(private AuditLogger $audit) {}

    public function set(Application $application, string $field, ?string $value, ?string $remarks = null): void
    {
        $from = $application->getAttribute($field);
        if ($from === $value) {
            return;
        }

        $application->forceFill([$field => $value])->save();

        ApplicationStatusHistory::create([
            'application_id' => $application->id,
            'field' => $field,
            'from_value' => $from,
            'to_value' => $value,
            'remarks' => $remarks,
            'user_id' => auth()->id(),
        ]);

        $this->audit->record("application.{$field}_changed", $application, [$field => $from], [$field => $value],
            $remarks ? ['remarks' => $remarks] : []);
    }
}
