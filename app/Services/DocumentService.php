<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\DocumentAccessLog;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only code that touches stored files. Uploads are checked by size,
 * detected MIME type, file signature and (optionally) ClamAV; downloads are
 * streamed from the private disk and logged. Storage paths never leave here.
 */
class DocumentService
{
    /** Leading bytes each accepted MIME type must start with. */
    private const SIGNATURES = [
        'application/pdf' => ['%PDF-'],
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png' => ["\x89PNG\r\n\x1A\n"],
    ];

    /** PDF features that execute or launch content; documents carrying them are refused. */
    private const PDF_ACTIVE_CONTENT = ['/JavaScript', '/JS ', '/JS(', '/Launch', '/EmbeddedFile', '/OpenAction /JavaScript', '/RichMedia', '/XFA'];

    public function __construct(private AuditLogger $audit) {}

    public function disk(): string
    {
        return config('recruitment.documents_disk', 'private');
    }

    public function store(
        Application $application,
        DocumentType $type,
        UploadedFile $file,
        User $uploader,
        ?Model $documentable = null,
        ?string $label = null,
        ?int $clarificationResponseId = null,
        string $uploadType = ApplicationDocument::ORIGINAL,
    ): ApplicationDocument {
        $undertaking = $uploadType === ApplicationDocument::UNDERTAKING;
        $this->assertAcceptable($type, $file, $undertaking);

        $mime = $this->detectMime($file);
        $extension = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };
        $path = 'applications/'.$application->uuid.'/'.Str::random(40).'.'.$extension;

        Storage::disk($this->disk())->put($path, file_get_contents($file->getRealPath()));

        $document = ApplicationDocument::create([
            'application_id' => $application->id,
            'document_type_id' => $type->id,
            'documentable_type' => $documentable?->getMorphClass(),
            'documentable_id' => $documentable?->getKey(),
            'clarification_response_id' => $clarificationResponseId,
            'label' => $label,
            'original_name' => $this->safeName($file->getClientOriginalName(), $extension),
            'disk' => $this->disk(),
            'path' => $path,
            'mime' => $mime,
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $uploader->id,
            'verification_status' => 'pending',
            'is_frozen' => false,
            'upload_type' => $undertaking ? ApplicationDocument::UNDERTAKING : ApplicationDocument::ORIGINAL,
            // An undertaking never stands for the original: the original stays awaited until staff record it.
            'original_status' => $undertaking ? 'awaited' : null,
            'original_due_stage' => $undertaking ? ApplicationDocument::ORIGINAL_DUE_STAGE : null,
        ]);

        $this->audit->record('document.uploaded', $document, [], [
            'document_type' => $type->code,
            'upload_type' => $document->upload_type,
            'original_name' => $document->original_name,
            'size' => $document->size,
        ], application: $application);

        return $document;
    }

    /** Remove a draft upload. Frozen (submitted) documents are never deleted. */
    public function delete(ApplicationDocument $document): void
    {
        if ($document->is_frozen) {
            throw ValidationException::withMessages(['document' => 'This document is part of a submitted application and cannot be removed.']);
        }

        Storage::disk($document->disk)->delete($document->path);
        $this->audit->record('document.deleted', $document, ['original_name' => $document->original_name]);
        $document->delete();
    }

    public function download(ApplicationDocument $document, User $user, bool $inline = false): StreamedResponse
    {
        $this->logAccess($user, 'application_document', $document->id, $document->application_id, $inline ? 'view' : 'download');

        return $this->stream($document->disk, $document->path, $document->original_name, $document->mime, $inline);
    }

    /** Stream any privately stored file (generated PDFs included). */
    public function stream(string $disk, string $path, string $name, string $mime, bool $inline = false): StreamedResponse
    {
        abort_unless(Storage::disk($disk)->exists($path), 404, 'File not found.');

        $response = Storage::disk($disk)->response($path, $name, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ], $inline ? 'inline' : 'attachment');

        return $response;
    }

    public function logAccess(?User $user, string $resourceType, int $resourceId, ?int $applicationId, string $action): void
    {
        DocumentAccessLog::create([
            'user_id' => $user?->id,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'application_id' => $applicationId,
            'action' => $action,
            'ip_address' => request()?->ip(),
        ]);
    }

    // ------------------------------------------------------------ checks

    /** A signed undertaking may be a scan or a PDF, whatever the original document's formats. */
    public const UNDERTAKING_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    private function assertAcceptable(DocumentType $type, UploadedFile $file, bool $undertaking = false): void
    {
        $allowed = $undertaking ? self::UNDERTAKING_MIMES : $type->allowed_mimes;
        if (! $file->isValid()) {
            $this->fail('The file could not be uploaded. Please try again.');
        }

        if ($file->getSize() > $type->max_kb * 1024) {
            $this->fail("The file must not be larger than {$this->humanSize($type->max_kb)}.");
        }

        if ($file->getSize() === 0) {
            $this->fail('The file is empty.');
        }

        $mime = $this->detectMime($file);
        if (! in_array($mime, $allowed, true)) {
            $this->fail('This document must be one of: '.$this->describeMimes($allowed).'.');
        }

        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 16);
        $signatures = self::SIGNATURES[$mime] ?? [];
        if ($signatures && ! collect($signatures)->contains(fn ($sig) => str_starts_with($head, $sig))) {
            $this->fail('The file contents do not match its type.');
        }

        if ($mime === 'application/pdf') {
            $this->assertNoActivePdfContent($file);
        }

        $limits = config("recruitment.image_dimensions.{$type->code}");
        if ($limits && ! $undertaking && str_starts_with($mime, 'image/')) {
            $size = @getimagesize($file->getRealPath());
            if (! $size) {
                $this->fail('The image could not be read.');
            }
            [$w, $h] = $size;
            if ($w < $limits['min_width'] || $h < $limits['min_height']) {
                $this->fail("The image is too small: it must be at least {$limits['min_width']} × {$limits['min_height']} pixels.");
            }
            if ($w > $limits['max_width'] || $h > $limits['max_height']) {
                $this->fail("The image is too large: it must be at most {$limits['max_width']} × {$limits['max_height']} pixels.");
            }
        }

        $this->scanForMalware($file);
    }

    private function assertNoActivePdfContent(UploadedFile $file): void
    {
        $contents = (string) file_get_contents($file->getRealPath());
        foreach (self::PDF_ACTIVE_CONTENT as $marker) {
            if (str_contains($contents, $marker)) {
                $this->fail('PDFs containing scripts, embedded files or launch actions are not accepted. Please print the document to a plain PDF and upload it again.');
            }
        }
    }

    /**
     * Optional ClamAV scan over clamd's INSTREAM protocol. Enabled by setting
     * CLAMAV_HOST; when unset no scan happens and signature checks still apply.
     */
    private function scanForMalware(UploadedFile $file): void
    {
        $host = config('recruitment.clamav.host');
        if (! $host) {
            return;
        }

        $socket = @fsockopen($host, (int) config('recruitment.clamav.port', 3310), $errno, $errstr, 5);
        if (! $socket) {
            if (config('recruitment.clamav.fail_closed', true)) {
                $this->fail('Uploads are temporarily unavailable. Please try again shortly.');
            }

            return;
        }

        fwrite($socket, "zINSTREAM\0");
        $handle = fopen($file->getRealPath(), 'rb');
        while (! feof($handle)) {
            $chunk = fread($handle, 8192);
            fwrite($socket, pack('N', strlen($chunk)).$chunk);
        }
        fclose($handle);
        fwrite($socket, pack('N', 0));
        $reply = trim((string) fgets($socket));
        fclose($socket);

        if (! str_ends_with($reply, 'OK')) {
            $this->fail('The file was rejected by the virus scanner.');
        }
    }

    private function detectMime(UploadedFile $file): string
    {
        return (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: 'application/octet-stream';
    }

    private function safeName(string $name, string $extension): string
    {
        $base = Str::of(pathinfo($name, PATHINFO_FILENAME))->ascii()->replaceMatches('/[^A-Za-z0-9._ -]/', '')->limit(120, '')->trim();

        return ($base->isEmpty() ? 'document' : (string) $base).'.'.$extension;
    }

    private function describeMimes(array $mimes): string
    {
        return collect($mimes)->map(fn ($m) => match ($m) {
            'application/pdf' => 'PDF',
            'image/jpeg' => 'JPEG',
            'image/png' => 'PNG',
            default => $m,
        })->implode(', ');
    }

    private function humanSize(int $kb): string
    {
        return $kb >= 1024 ? round($kb / 1024, 1).' MB' : $kb.' KB';
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
