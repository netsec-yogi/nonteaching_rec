<?php

namespace App\Services\Maintenance;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Another backup or restore is queued or running, or the target is in use by one (HTTP 409). */
class OperationInProgress extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
