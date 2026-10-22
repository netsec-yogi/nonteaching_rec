<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApplicationFilter;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->reports->definitions($request->user()));
    }

    public function show(Request $request, string $key): JsonResponse
    {
        return response()->json($this->reports->preview($key, $request->only(ApplicationFilter::FILTERS), $request->user()));
    }

    public function export(Request $request, string $key, string $format)
    {
        abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

        return $this->reports->export($key, $format, $request->only(ApplicationFilter::FILTERS), $request->user());
    }
}
