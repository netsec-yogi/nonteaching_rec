<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationVersion;
use App\Models\RefereeResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Generated documents. Application and recommendation PDFs are written once
 * to private storage and their SHA-256 recorded; receipts and reports are
 * rendered on demand.
 */
class PdfService
{
    public function __construct(private SettingsService $settings) {}

    private function disk(): string
    {
        return config('recruitment.documents_disk', 'private');
    }

    private function render(string $view, array $data, string $orientation = 'portrait'): string
    {
        $pdf = Pdf::loadView($view, $data + [
            'institute' => $this->settings->get('institute.name'),
            'instituteAddress' => $this->settings->get('institute.address'),
            'logo' => $this->logo(),
        ])->setPaper('a4', $orientation)->setOption([
            'defaultFont' => 'Noto Sans',
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isFontSubsettingEnabled' => true,
            // Fonts are bundled in resources/fonts and loaded via @font-face in pdf/_styles.
            'chroot' => base_path(),
            'fontDir' => storage_path('fonts'),
            'fontCache' => storage_path('fonts'),
        ]);

        // Page numbers are stamped after layout, when the total is known.
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Noto Sans') ?? $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        // Right end of the footer line (footer sits 12 mm above the bottom edge; 15 mm side margin).
        $canvas->page_text($canvas->get_width() - 42.5 - 52, $canvas->get_height() - 31.5, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 6.75, [0.42, 0.45, 0.5]);

        return $pdf->output();
    }

    private function logo(): ?string
    {
        $path = public_path('images/institute-logo.svg');

        return is_file($path) ? 'data:image/svg+xml;base64,'.base64_encode(file_get_contents($path)) : null;
    }

    /** Render and attach the PDF for a frozen application version. */
    public function applicationVersion(ApplicationVersion $version): ApplicationVersion
    {
        if ($version->pdf_path) {
            return $version;
        }
        $snapshot = $version->snapshot;
        $binary = $this->render('pdf.application', ['s' => $snapshot, 'version' => $version, 'photo' => $this->photoDataUri($version->application), 'signature' => $this->signatureImage($version->application)]);
        $path = 'applications/'.$snapshot['meta']['uuid'].'/application-v'.$version->version.'.pdf';
        Storage::disk($this->disk())->put($path, $binary);

        $version->forceFill(['pdf_disk' => $this->disk(), 'pdf_path' => $path, 'pdf_sha256' => hash('sha256', $binary)])->save();

        return $version;
    }

    /** A draft preview straight from a snapshot array, never stored. */
    public function applicationPreview(array $snapshot): string
    {
        $application = Application::where('uuid', $snapshot['meta']['uuid'] ?? null)->first();

        return $this->render('pdf.application', ['s' => $snapshot, 'version' => null, 'draft' => true, 'photo' => $this->photoDataUri($application), 'signature' => $this->signatureImage($application)]);
    }

    /** The candidate's photograph as a data URI (dompdf runs with remote access off), or null. */
    private function photoDataUri(?Application $application): ?string
    {
        $photo = $application?->documents()->whereHas('documentType', fn ($q) => $q->where('code', 'photo'))->latest('id')->first();
        if (! $photo || ! Storage::disk($photo->disk)->exists($photo->path)) {
            return null;
        }

        return 'data:'.$photo->mime.';base64,'.base64_encode(Storage::disk($photo->disk)->get($photo->path));
    }

    /**
     * The candidate's signature as a data URI with a printed size that keeps its proportions inside a
     * 50 mm × 18 mm box (dompdf ignores object-fit, so the size is computed here), or null.
     *
     * @return array{uri: string, width_mm: float, height_mm: float}|null
     */
    private function signatureImage(?Application $application): ?array
    {
        $doc = $application?->documents()->where('upload_type', 'original')
            ->whereHas('documentType', fn ($q) => $q->where('code', 'signature'))->latest('id')->first();
        if (! $doc || ! Storage::disk($doc->disk)->exists($doc->path)) {
            return null;
        }
        $bytes = Storage::disk($doc->disk)->get($doc->path);
        $size = @getimagesizefromstring($bytes);
        if (! $size || ! $size[0] || ! $size[1]) {
            return null;
        }
        $scale = min(50 / $size[0], 18 / $size[1]);

        return ['uri' => 'data:'.$doc->mime.';base64,'.base64_encode($bytes), 'width_mm' => round($size[0] * $scale, 2), 'height_mm' => round($size[1] * $scale, 2)];
    }

    public function acknowledgment(Application $application): string
    {
        $application->loadMissing(['personal', 'post', 'advertisement', 'payment', 'referees', 'versions']);

        return $this->render('pdf.acknowledgment', ['a' => $application, 'version' => $application->versions->first()]);
    }

    public function refereeRecommendation(RefereeResponse $response): RefereeResponse
    {
        if ($response->pdf_path) {
            return $response;
        }
        $response->loadMissing(['application.personal', 'application.post', 'application.advertisement', 'referee', 'amendments.amender']);
        $binary = $this->render('pdf.referee', ['r' => $response]);
        $path = 'applications/'.$response->application->uuid.'/referees/recommendation-'.$response->referee->position.'-'.$response->id.'.pdf';
        Storage::disk($this->disk())->put($path, $binary);

        $response->forceFill(['pdf_disk' => $this->disk(), 'pdf_path' => $path, 'pdf_sha256' => hash('sha256', $binary)])->save();

        return $response;
    }

    /** Recommendation with any audited amendments appended; rendered on demand. */
    public function refereeRecommendationWithAmendments(RefereeResponse $response): string
    {
        $response->loadMissing(['application.personal', 'application.post', 'application.advertisement', 'referee', 'amendments.amender']);

        return $this->render('pdf.referee', ['r' => $response]);
    }

    public function scrutinyReport(array $report): string
    {
        return $this->render('pdf.scrutiny-report', ['report' => $report]);
    }

    /** @param list<string> $headings @param iterable<list<scalar|null>> $rows */
    public function table(string $title, array $headings, iterable $rows, array $filters = []): string
    {
        return $this->render('pdf.table', [
            'title' => $title,
            'headings' => $headings,
            'rows' => $rows,
            'filters' => $filters,
        ], count($headings) > 6 && count($headings) <= 14 ? 'landscape' : 'portrait');
    }
}
