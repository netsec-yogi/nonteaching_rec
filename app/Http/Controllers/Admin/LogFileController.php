<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Maintenance\LogFiles;
use App\Services\Maintenance\LogReader;
use App\Services\Maintenance\MaintenanceSettings;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Application log files: list and view (logs.view, masked), download the originals
 * (logs.download), archive / delete / retention (logs.manage). Files are named, never pathed;
 * nothing can be edited.
 */
class LogFileController extends Controller
{
    public function __construct(private LogFiles $logs, private AuditLogger $audit) {}

    public function index(Request $request, MaintenanceSettings $settings): JsonResponse
    {
        $user = $request->user();

        return response()->json($this->logs->list() + [
            'storage' => $this->logs->storage(),
            'settings' => $settings->logs(),
            'rotation' => [
                'channel' => config('logging.default'),
                'daily_days' => (int) config('logging.channels.daily.max_files', 14),
            ],
            'levels' => LogReader::LEVELS,
            'categories' => LogReader::CATEGORIES,
            'can' => ['download' => $user->hasPermission('logs.download'), 'manage' => $user->hasPermission('logs.manage')],
        ]);
    }

    public function entries(Request $request, LogReader $reader): JsonResponse
    {
        $f = $request->validate([
            'file' => ['required', 'string', 'max:200'],
            'levels' => ['nullable', 'array'],
            'levels.*' => [Rule::in(LogReader::LEVELS)],
            'q' => ['nullable', 'string', 'max:200'],
            'exception' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', Rule::in(array_keys(LogReader::CATEGORIES))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'cursor' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'between:1,200'],
        ]);
        $path = $this->logs->resolve('log', $f['file']);

        // Viewing is audited once per file per half hour, not for every page or filter change.
        if (Cache::add('logs.viewed:'.$request->user()->id.':'.$f['file'], true, now()->addMinutes(30))) {
            $this->audit->record('logs.viewed', null, [], ['file' => $f['file']], user: $request->user());
        }

        // A date alone covers the whole day; a time without seconds covers the whole minute.
        $time = fn (?string $v, bool $end) => $v === null ? null : Carbon::parse($v)->format(match (true) {
            strlen($v) <= 10 => $end ? 'Y-m-d 23:59:59' : 'Y-m-d 00:00:00',
            strlen($v) <= 16 => $end ? 'Y-m-d H:i:59' : 'Y-m-d H:i:00',
            default => 'Y-m-d H:i:s',
        });
        $result = $reader->entries($path, [
            'levels' => $f['levels'] ?? [],
            'q' => $f['q'] ?? null,
            'exception' => $f['exception'] ?? null,
            'category' => $f['category'] ?? null,
            'from' => $time($f['from'] ?? null, false),
            'to' => $time($f['to'] ?? null, true),
        ], isset($f['cursor']) ? (int) $f['cursor'] : null, (int) ($f['limit'] ?? 50), (int) config('maintenance.logs.scan_bytes'));

        clearstatcache(true, $path);

        return response()->json($result + ['file' => ['name' => $f['file'], 'size' => (int) filesize($path), 'modified_at' => date(DATE_ATOM, (int) filemtime($path))]]);
    }

    public function download(Request $request): BinaryFileResponse
    {
        $f = $request->validate(['file' => ['required', 'string', 'max:200'], 'kind' => ['nullable', 'in:log,archive']]);
        $kind = $f['kind'] ?? 'log';
        $path = $this->logs->resolve($kind, $f['file']);
        $this->audit->record('logs.downloaded', null, [], ['file' => $f['file'], 'kind' => $kind, 'size' => (int) filesize($path)], user: $request->user());

        return response()->download($path, $f['file'], [
            'Content-Type' => $kind === 'archive' ? 'application/gzip' : 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private',
        ]);
    }

    public function zip(Request $request): BinaryFileResponse
    {
        $f = $request->validate([
            'files' => ['nullable', 'array', 'max:100'], 'files.*' => ['string', 'max:200'],
            'archives' => ['nullable', 'array', 'max:100'], 'archives.*' => ['string', 'max:200'],
        ]);
        $selected = array_merge(
            array_map(fn ($n) => ['kind' => 'log', 'name' => $n], $f['files'] ?? []),
            array_map(fn ($n) => ['kind' => 'archive', 'name' => $n], $f['archives'] ?? []),
        );
        abort_if(! $selected, 422, 'Select at least one file.');
        $tmp = $this->logs->zip($selected);
        $this->audit->record('logs.downloaded', null, [], ['files' => array_column($selected, 'name'), 'kind' => 'zip'], user: $request->user());

        return response()->download($tmp, 'logs-'.now()->format('Ymd-His').'.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store, private'])
            ->deleteFileAfterSend();
    }

    public function archive(Request $request): JsonResponse
    {
        $f = $request->validate(['files' => ['required', 'array', 'min:1', 'max:100'], 'files.*' => ['string', 'max:200']]);

        return response()->json($this->each($f['files'], fn ($name) => $this->logs->archive($name, $request->user())));
    }

    public function destroy(Request $request): JsonResponse
    {
        $f = $request->validate([
            'confirm' => ['accepted'],
            'files' => ['required', 'array', 'min:1', 'max:100'],
            'files.*.kind' => ['required', 'in:log,archive'],
            'files.*.name' => ['required', 'string', 'max:200'],
        ]);

        return response()->json($this->each($f['files'], function ($file) use ($request) {
            $this->logs->delete($file['kind'], $file['name'], $request->user());

            return $file['name'];
        }));
    }

    public function settings(MaintenanceSettings $settings): JsonResponse
    {
        return response()->json(['values' => $settings->logs()]);
    }

    public function updateSettings(Request $request, MaintenanceSettings $settings): JsonResponse
    {
        [$old, $new] = $settings->updateLogs((array) $request->input('values', []));
        if ($new) {
            $this->audit->record('logs.settings_updated', null, $old, $new, user: $request->user());
        }

        return response()->json(['values' => $settings->logs()]);
    }

    /** Apply an action to each file; one refusal does not stop the rest. */
    private function each(array $files, callable $action): array
    {
        $done = $failed = [];
        foreach ($files as $file) {
            $name = is_array($file) ? (string) ($file['name'] ?? '') : (string) $file;
            try {
                $done[] = ['name' => $name, 'result' => $action($file)];
            } catch (ValidationException $e) {
                $failed[] = ['name' => $name, 'error' => collect($e->errors())->flatten()->first()];
            } catch (HttpException $e) {
                $failed[] = ['name' => $name, 'error' => $e->getMessage()];
            } catch (Throwable $e) {
                $failed[] = ['name' => $name, 'error' => $e->getMessage()];
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }
}
