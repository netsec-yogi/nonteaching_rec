<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(SettingsService $settings): JsonResponse
    {
        return response()->json(['values' => $settings->all(), 'keys' => array_keys(SettingsService::DEFAULTS)]);
    }

    /** Configured defaults that pre-fill new advertisements and document types. */
    public function defaults(SettingsService $settings): JsonResponse
    {
        return response()->json([
            'referee_deadline_days' => (int) $settings->get('referee.default_deadline_days'),
            'upload_max_kb' => (int) $settings->get('uploads.max_kb_default'),
            'retention_years' => (int) $settings->get('retention.default_years'),
        ]);
    }

    public function update(Request $request, SettingsService $settings, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['values' => ['required', 'array']]);
        $allowed = array_keys(SettingsService::DEFAULTS);
        $old = $settings->all();
        foreach ($data['values'] as $key => $value) {
            abort_unless(in_array($key, $allowed, true), 422, "Unknown setting [$key].");
            $default = SettingsService::DEFAULTS[$key];
            if (is_int($default)) {
                abort_unless(is_numeric($value) && (int) $value >= 0, 422, "Setting [$key] must be a non-negative number.");
                $value = (int) $value;
            } elseif (is_bool($default)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } else {
                $value = mb_substr((string) $value, 0, 10000);
            }
            if (($old[$key] ?? null) !== $value) {
                $settings->set($key, $value);
                $audit->record('settings.updated', null, [$key => $old[$key] ?? null], [$key => $value]);
            }
        }

        return response()->json(['values' => $settings->all()]);
    }
}
